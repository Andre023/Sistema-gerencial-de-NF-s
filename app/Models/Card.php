<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma divergência da nota. Ciclo de vida:
 *
 *   aberto (pré-lote) → corrigido (compras marca) → resolvido (pré-lote confirma)
 *                        ↑___________ reaberto se ainda estiver errado
 */
class Card extends Model
{
    protected $table = 'cards';

    protected $fillable = [
        'nota_id',
        'tipo',
        'status',
        'detalhe',
        'aberto_por',
        'corrigido_por',
        'corrigido_em',
        'resolvido_por',
        'resolvido_em',
        'reaberturas',
    ];

    protected $casts = [
        'corrigido_em' => 'datetime',
        'resolvido_em' => 'datetime',
        'reaberturas'  => 'integer',
    ];

    public const TIPOS = ['cadastro', 'regra', 'custo', 'quantidade', 'sem_pedido'];

    /**
     * Tipos que o comprador corrige no ERP e marca aqui.
     * "Regra" fica de fora: é o pré-lote que resolve direto quando estiver acertada.
     * "Sem pedido" (nota chegou sem o pedido de compra) é de compras: quem lança
     * o pedido no ERP é o setor de compras.
     */
    public const TIPOS_COMPRAS = ['cadastro', 'custo', 'quantidade', 'sem_pedido'];

    // Corrigir (compras) já resolve o card — não há estado intermediário.
    public const STATUS_ABERTO    = 'aberto';
    public const STATUS_RESOLVIDO = 'resolvido';

    public const STATUS = [
        self::STATUS_ABERTO,
        self::STATUS_RESOLVIDO,
    ];

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function abertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberto_por');
    }

    public function corrigidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrigido_por');
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    /** Ainda pesa na nota (impede liberação). */
    public function ativo(): bool
    {
        return $this->status !== self::STATUS_RESOLVIDO;
    }

    /**
     * Compras só corrige os tipos que ela mesma arruma no ERP
     * (cadastro, custo, quantidade). Card de regra é do pré-lote. Admin pode tudo.
     */
    public function podeSerCorrigidoPor(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->podeCorrigirCard() && in_array($this->tipo, self::TIPOS_COMPRAS, true);
    }
}
