<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma entrada de dinheiro do fornecedor, com data.
 *
 * As parcelas são a verdade do quanto já entrou; o `pago` do atendimento é a
 * soma delas, mantida para a lista e a exportação não terem de somar a cada
 * leitura. Ver CampanhaAtendimento::recalcularPago.
 */
class CampanhaParcela extends Model
{
    protected $table = 'campanha_parcelas';

    protected $fillable = [
        'campanha_atendimento_id',
        'valor',
        'data',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'data'  => 'date',
    ];

    public function atendimento(): BelongsTo
    {
        return $this->belongsTo(CampanhaAtendimento::class, 'campanha_atendimento_id');
    }

    public function paraTela(): array
    {
        return [
            'id'    => $this->id,
            'valor' => (float) $this->valor,
            // YYYY-MM-DD: é o formato que o <input type="date"> entende, e a tela
            // formata para o brasileiro na hora de mostrar.
            'data'  => $this->data?->toDateString(),
        ];
    }
}
