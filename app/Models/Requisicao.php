<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisicao extends Model
{
    use SoftDeletes;

    protected $table = 'requisicoes';

    protected $fillable = [
        'numero_nota',
        'fornecedor_id',
        'user_id',
        'loja',
        'motivo',
        'observacao',
        'status',
    ];

    protected $casts = [
        'loja' => 'integer',
    ];

    // Lojas válidas no sistema
    public const LOJAS = [1, 2, 3, 9, 11, 12];

    // Motivos válidos
    public const MOTIVOS = ['Cadastro', 'Preço', 'Regra', 'Quantidade'];

    // Status válidos
    public const STATUS = ['Pendente', 'Atendida'];

    public function fornecedor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditorias(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequisicaoAuditoria::class);
    }

    /**
     * Indica se a requisição veio de um dia anterior à data consultada
     */
    public function isAtrasada(string $dataFiltro): bool
    {
        return $this->created_at->toDateString() < $dataFiltro;
    }
}
