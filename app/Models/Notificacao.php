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

    public const TIPOS = [
        self::TIPO_DIVERGENCIA,
        self::TIPO_CORRIGIDO,
        self::TIPO_REABERTO,
        self::TIPO_LIBERADA,
    ];

    /** Quantas o sino mostra na lista (o contador conta todas as pendentes) */
    public const LIMITE_LISTA = 15;

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
