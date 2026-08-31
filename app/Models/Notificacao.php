<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aviso dirigido a uma pessoa sobre uma nota. Quem decide quem recebe o quê
 * é o App\Services\Notificador — aqui fica só o registro e o formato de tela.
 */
class Notificacao extends Model
{
    protected $table = 'notificacoes';

    protected $fillable = [
        'user_id',
        'nota_id',
        'tipo',
        'dados',
        'lida_em',
        'encerrada_em',
    ];

    protected $casts = [
        'dados'        => 'array',
        'lida_em'      => 'datetime',
        'encerrada_em' => 'datetime',
    ];

    /** Divergência aberta — o pré-lote diagnosticou, compras precisa arrumar no ERP */
    public const TIPO_DIVERGENCIA = 'divergencia';
    /** Compras corrigiu — quem abriu o card precisa reconferir */
    public const TIPO_CORRIGIDO = 'corrigido';
    /** Reconferido e ainda errado — compras precisa olhar de novo */
    public const TIPO_REABERTO = 'reaberto';
    /** Fim da linha — quem lançou a nota pode tocar o recebimento */
    public const TIPO_LIBERADA = 'liberada';
    /** Nota recém lançada (caminhão na porta) — o pré-lote precisa analisar */
    public const TIPO_LANCADA = 'lancada';
    /**
     * Card que só quem tem a mercadoria e o papel na mão resolve (recusa e
     * devolução) — pré-lote e recebimento precisam agir.
     *
     * Tipo PRÓPRIO, e não uma reutilização de TIPO_DIVERGENCIA, porque os dois
     * avisos convivem na mesma nota: encerrar o de compras encerraria este
     * junto, e o pré-lote perderia a cobrança de um card que continua aberto.
     */
    public const TIPO_DOCA = 'doca';

    public const TIPOS = [
        self::TIPO_DIVERGENCIA,
        self::TIPO_CORRIGIDO,
        self::TIPO_REABERTO,
        self::TIPO_LIBERADA,
        self::TIPO_LANCADA,
        self::TIPO_DOCA,
    ];

    /** Quantas o sino mostra na lista (o contador conta todas as pendentes) */
    public const LIMITE_LISTA = 15;

    /**
     * Por quantos dias um aviso fica no sino.
     *
     * O sino é o "olha o que acabou de acontecer", não o arquivo do que está
     * pendente. Sem prazo, ele virava as duas coisas ao mesmo tempo e perdia as
     * duas: havia 1.205 avisos pendentes acumulados entre 27 contas, e um sino
     * que sempre marca número alto não avisa mais nada — vira paisagem.
     *
     * A nota parada NÃO se perde por isso: ela continua na fila, e lá o
     * envelhecimento tem cor própria (atenção, alerta, crítico aos 7 dias). A
     * fila é quem cobra o que está velho; o sino cobra o que é novo.
     *
     * Conta do `updated_at`, e não da criação: existe UMA notificação viva por
     * nota, e ela é REESCRITA quando chega outra divergência na mesma nota. Se
     * contasse da criação, a novidade de hoje herdaria a idade da primeira
     * divergência e nasceria fora do sino.
     */
    public const DIAS_NO_SINO = 3;

    /**
     * Depois de quantos dias um aviso JÁ RESOLVIDO é apagado.
     *
     * Só vale para o que não pesa mais no sino — lido ou encerrado. O aviso
     * ainda pendente nunca sai, por mais velho que seja: se ninguém agiu, ele
     * continua sendo a cobrança.
     *
     * Dois meses porque este não é o livro de registro da nota — quem guarda o
     * histórico é `ocorrencias`, e ele não some. Aqui é só a caixa de entrada.
     *
     * Ver App\Console\Commands\LimparNotificacoesAntigas.
     */
    public const DIAS_DE_VIDA = 60;

    // ─── Relações ───────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    // ─── Escopos ────────────────────────────────────────────────────────────────

    /** Ainda pesa no sino: ninguém leu e o motivo continua de pé. */
    public function scopePendentes(Builder $q): Builder
    {
        return $q->whereNull('lida_em')->whereNull('encerrada_em');
    }

    /** A que deve acumular novidade em vez de virar uma segunda linha. */
    public function scopeViva(Builder $q): Builder
    {
        return $q->whereNull('encerrada_em');
    }

    /**
     * Dentro da janela do sino (ver DIAS_NO_SINO).
     *
     * Só afeta o que a pessoa VÊ: a linha continua no banco, ainda pendente, e
     * a nota continua na fila cobrando por conta própria. Nada é resolvido nem
     * apagado aqui — o aviso só para de ocupar espaço numa lista que tem 15
     * lugares e cabia mostrar o que é recente.
     */
    public function scopeNoSino(Builder $q): Builder
    {
        return $q->where('updated_at', '>=', now()->subDays(self::DIAS_NO_SINO));
    }

    // ─── Tela ───────────────────────────────────────────────────────────────────

    /**
     * Formato do sino. Requer a relação nota (com fornecedor) carregada — quem
     * monta a lista é o Notificador::paraUsuario().
     */
    public function paraTela(): array
    {
        return [
            'id'           => $this->id,
            'tipo'         => $this->tipo,
            'nota_id'      => $this->nota_id,
            'numero_nota'  => $this->nota?->numero_nota,
            'fornecedor'   => $this->nota?->fornecedor?->nome,
            'loja'         => $this->nota?->loja,
            'tipos'        => $this->dados['tipos'] ?? [],
            'autor'        => $this->dados['autor'] ?? null,
            'lida'         => $this->lida_em !== null,
            'encerrada'    => $this->encerrada_em !== null,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
