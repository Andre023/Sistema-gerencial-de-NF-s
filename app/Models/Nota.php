<?php

namespace App\Models;

use App\Models\Concerns\TemIdade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nota extends Model
{
    use SoftDeletes, TemIdade;

    protected $table = 'notas';

    protected $fillable = [
        'numero_nota',
        'fornecedor_id',
        'user_id',
        'loja',
        'origem',
        'ceasa',
        'observacao',
        'liberada_por',
        'liberada_em',
        'recebida_em',
        'visualizando_por',
        'visualizando_em',
        'cancelada_em',
        'cancelada_por',
        'motivo_cancelamento',
        'origem_alterada_em',
        'origem_anterior',
    ];

    protected $casts = [
        'loja'            => 'integer',
        'ceasa'           => 'integer', // 0 = não · 1 = CEASA 1 · 2 = CEASA 2 · 3 = CEASA (neutro)
        'liberada_em'     => 'datetime',
        'recebida_em'     => 'datetime',
        'visualizando_em' => 'datetime',
        'cancelada_em'       => 'datetime',
        'origem_alterada_em' => 'datetime',
    ];

    public const LOJAS = [1, 2, 3, 7, 9, 11, 12, 13, 18];

    /** recebimento = caminhão na porta (prioridade) | pre_lote = antecipada */
    public const ORIGENS = ['recebimento', 'pre_lote'];

    /** Como cada fila aparece para o usuário (mensagens de duplicidade, etc.) */
    public const ORIGEM_LABEL = [
        'recebimento' => 'Caminhão na porta',
        'pre_lote'    => 'Pré-lote',
    ];

    // Estados derivados dos cards — nunca gravados, sempre calculados
    public const STATUS_PENDENTE    = 'pendente';        // aguardando análise do pré-lote
    public const STATUS_DIVERGENCIA = 'com_divergencia'; // tem card aberto
    public const STATUS_RECONFERIR  = 'reconferir';      // cards corrigidos aguardando o pré-lote
    public const STATUS_LIBERADA    = 'liberada';
    public const STATUS_CANCELADA   = 'cancelada';       // o fornecedor cancelou a NF

    // ─── Relações ───────────────────────────────────────────────────────────────

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function liberadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberada_por');
    }

    /** Quem está "olhando" a nota agora (o 🙋‍♂️). */
    public function visualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visualizando_por');
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    /**
     * De quando conta o envelhecimento: se a nota trocou de fila, o relógio
     * reinicia na data da troca — ela esperou na fila ANTERIOR, e as cores devem
     * refletir o tempo na fila ATUAL. A origem anterior vira o selo
     * "Pré-lote desde 19/06" (ver paraTabela).
     */
    public function inicioContagem(): \Carbon\Carbon
    {
        return $this->origem_alterada_em ?? $this->created_at;
    }

    /** Solta a reserva (o 🙋‍♂️ some). Chamado quando a pessoa age na nota. */
    public function limparVisualizacao(): void
    {
        if ($this->visualizando_por !== null) {
            $this->update(['visualizando_por' => null, 'visualizando_em' => null]);
        }
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function comentarios(): MorphMany
    {
        return $this->morphMany(Comentario::class, 'comentavel');
    }

    /** Documentos e fotos da nota (canhoto, avaria). Vida curta — ver Anexo. */
    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class);
    }

    /**
     * Apaga os anexos com os arquivos do disco.
     *
     * Usado quando a nota sai de cena (cancelada, excluída) e pelo job dos 2
     * dias. Vai um a um de propósito: `->delete()` na relação limparia as
     * linhas e deixaria os arquivos ocupando disco sem ninguém apontando
     * para eles — órfãos que só apareceriam quando a partição enchesse.
     */
    public function apagarAnexos(): int
    {
        $quantos = 0;

        foreach ($this->anexos()->get() as $anexo) {
            $anexo->apagarComArquivo();
            $quantos++;
        }

        return $quantos;
    }

    // ─── Escopos ────────────────────────────────────────────────────────────────

    /**
     * Notas que contam como trabalho real: exclui as CANCELADAS.
     *
     * As excluídas já saem sozinhas (SoftDeletes). A cancelada, não: ela fica na
     * tabela com liberada_em nulo e, sem este filtro, é contada como "pendente"
     * para sempre — envenenando taxa de resolução e a lista de mais antigas.
     * Use em TODA consulta de estatística/painel. Ver docs/NOTAS_CANCELADAS.md.
     */
    public function scopeAtivas(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNull('cancelada_em');
    }

    // ─── Estado derivado ────────────────────────────────────────────────────────

    /**
     * O status da nota nasce dos cards — não existe campo para dessincronizar.
     * Requer a relação cards carregada (o controller sempre carrega).
     */
    public function statusCalculado(): string
    {
        // Cancelada encerra a nota: nem os cards nem a liberação importam mais
        if ($this->cancelada_em) {
            return self::STATUS_CANCELADA;
        }

        if ($this->liberada_em) {
            return self::STATUS_LIBERADA;
        }

        $cards = $this->cards;

        // Algum card ainda aberto → há divergência a corrigir
        if ($cards->contains(fn($c) => $c->status === Card::STATUS_ABERTO)) {
            return self::STATUS_DIVERGENCIA;
        }

        // Teve divergência e tudo foi corrigido → aguarda a conferência final
        if ($cards->isNotEmpty()) {
            return self::STATUS_RECONFERIR;
        }

        // Nunca teve card → só aguardando a análise do pré-lote
        return self::STATUS_PENDENTE;
    }

    /** Nenhum card aberto nem aguardando reconferência — pode ser liberada. */
    public function podeSerLiberada(): bool
    {
        return $this->liberada_em === null &&
            ! $this->cards->contains(fn($c) => $c->status !== Card::STATUS_RESOLVIDO);
    }

    /**
     * A nota recarregada do banco, pronta para a tela.
     *
     * É a MESMA linha que o evento de tempo real transmite, e mora aqui para
     * existir uma cópia só: a lista de relações estava escrita no
     * NotaAtualizada, e uma segunda cópia no controller sairia de sincronia no
     * dia em que alguém acrescentasse uma relação — com o sintoma mudo de a tela
     * receber a nota sem o dado novo, dependendo de por onde ela chegou.
     *
     * `fresh` e não `$this` porque a ação acabou de gravar: sem recarregar, o
     * que volta é o estado de antes da alteração.
     *
     * Formatada relativa a HOJE, que é quando a ação está acontecendo.
     */
    public function paraTelaAgora(): ?array
    {
        $nota = $this->fresh([
            'fornecedor:id,nome,prioridade',
            'user:id,name,avatar_tipo,avatar_valor',
            'liberadaPor:id,name,avatar_tipo,avatar_valor',
            'visualizadaPor:id,name,avatar_tipo,avatar_valor',
            'canceladaPor:id,name,avatar_tipo,avatar_valor',
            'cards',
        ]);

        if (! $nota) {
            return null;
        }

        // Sem contar os anexos aqui, paraTabela() cai no `?? 0` e o contador do
        // botão zera na tela — inclusive no evento do próprio upload.
        $nota->loadCount(['comentarios', 'anexos']);

        return $nota->paraTabela(now()->toDateString());
    }

    /**
     * Formato consumido pela tela (listagem e evento de tempo real). Requer as
     * relações fornecedor/user/liberadaPor/cards e comentarios_count carregados.
     */
    public function paraTabela(string $dataFiltro): array
    {
        return [
            'id'           => $this->id,
            'numero_nota'  => $this->numero_nota,
            'fornecedor'   => $this->fornecedor,
            'user'         => $this->user,
            'loja'         => $this->loja,
            'origem'       => $this->origem,
            'ceasa'        => (int) $this->ceasa,
            'observacao'   => $this->observacao,
            'status'       => $this->statusCalculado(),
            'cards'        => $this->cards->map(fn($c) => [
                'id'          => $c->id,
                'tipo'        => $c->tipo,
                'status'      => $c->status,
                'detalhe'     => $c->detalhe,
                'reaberturas' => $c->reaberturas,
            ])->values(),
            'liberada_por' => $this->liberadaPor,
            'liberada_em'  => $this->liberada_em,
            'recebida_em'  => $this->recebida_em,
            'cancelada_em'  => $this->cancelada_em,
            'cancelada_por' => $this->canceladaPor
                ? ['id' => $this->canceladaPor->id, 'name' => $this->canceladaPor->name]
                : null,
            'motivo_cancelamento' => $this->motivo_cancelamento,
            // Veio de outra fila: a tela mostra "Pré-lote desde 19/06"
            'origem_anterior'      => $this->origem_anterior,
            'origem_anterior_data' => $this->origem_anterior ? $this->created_at->format('d/m') : null,
            'origem_alterada_em'   => $this->origem_alterada_em,
            'visualizando_por' => $this->visualizadaPor
                ? ['id' => $this->visualizadaPor->id, 'name' => $this->visualizadaPor->name,
                   'avatar' => $this->visualizadaPor->avatar]
                : null,
            'visualizando_em'  => $this->visualizando_em,
            'comentarios_count' => $this->comentarios_count ?? 0,
            'anexos_count'      => $this->anexos_count ?? 0,
            'created_at'   => $this->created_at,
            'atrasada'     => $this->isAtrasada($dataFiltro),
            'dias_aberta'  => $this->diasEmAberto($dataFiltro),
            'nivel'        => $this->nivelAlerta($dataFiltro),
            'data_origem'  => $this->created_at->format('d/m'),
        ];
    }
}
