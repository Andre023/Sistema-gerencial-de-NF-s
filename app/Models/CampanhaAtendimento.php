<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um fornecedor que o comprador já atendeu na campanha, e o que já entrou dele.
 *
 * Os valores são COPIADOS de campanha_fornecedores no momento em que a linha
 * nasce, e não lidos de lá depois — a planilha de compras troca aquela tabela
 * inteira a cada envio, e sem a cópia a meta combinada em agosto passaria a ser
 * cobrada pelo faturamento de setembro. Ver a migration.
 */
class CampanhaAtendimento extends Model
{
    protected $table = 'campanha_atendimentos';

    protected $fillable = [
        'user_id',
        'fornecedor',
        'chave',
        'faturamento',
        'investimento',
        'pago',
    ];

    protected $casts = [
        'faturamento'  => 'decimal:2',
        'investimento' => 'decimal:2',
        'pago'         => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quanto do combinado já entrou, em porcentagem.
     *
     * `null` quando a meta é zero: dividir por ela não daria 0% nem 100% — não
     * daria número nenhum, e mostrar "0%" ali afirmaria que falta tudo de uma
     * meta que não existe.
     */
    public function percentualPago(): ?float
    {
        $meta = (float) $this->investimento;

        return $meta > 0 ? ((float) $this->pago / $meta) * 100 : null;
    }

    /**
     * Quanto ainda falta.
     *
     * Nunca negativo: quem pagou a mais não "deve menos que nada" — falta zero,
     * e o excedente aparece pelo percentual passando de 100%.
     */
    public function falta(): float
    {
        return max(0, (float) $this->investimento - (float) $this->pago);
    }

    /** Formato que a tela consome. */
    public function paraTela(): array
    {
        return [
            'id'            => $this->id,
            'fornecedor'    => $this->fornecedor,
            'faturamento'   => $this->faturamento === null ? null : (float) $this->faturamento,
            'investimento'  => (float) $this->investimento,
            'pago'          => (float) $this->pago,
            'percentualPago' => $this->percentualPago(),
            'falta'         => $this->falta(),
            'em'            => $this->created_at,
        ];
    }
}
