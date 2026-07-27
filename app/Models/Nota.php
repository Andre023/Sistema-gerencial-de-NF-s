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
    ];

    protected $casts = [
        'loja'            => 'integer',
        'ceasa'           => 'boolean',
        'liberada_em'     => 'datetime',
        'recebida_em'     => 'datetime',
        'visualizando_em' => 'datetime',
    ];

    public const LOJAS = [1, 2, 3, 9, 11, 12];

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

    // ─── Estado derivado ────────────────────────────────────────────────────────

    /**
     * O status da nota nasce dos cards — não existe campo para dessincronizar.
     * Requer a relação cards carregada (o controller sempre carrega).
     */
    public function statusCalculado(): string
    {
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
            'ceasa'        => (bool) $this->ceasa,
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
            'visualizando_por' => $this->visualizadaPor
                ? ['id' => $this->visualizadaPor->id, 'name' => $this->visualizadaPor->name,
                   'avatar' => $this->visualizadaPor->avatar]
                : null,
            'visualizando_em'  => $this->visualizando_em,
            'comentarios_count' => $this->comentarios_count ?? 0,
            'created_at'   => $this->created_at,
            'atrasada'     => $this->isAtrasada($dataFiltro),
            'dias_aberta'  => $this->diasEmAberto($dataFiltro),
            'nivel'        => $this->nivelAlerta($dataFiltro),
            'data_origem'  => $this->created_at->format('d/m'),
        ];
    }
}
