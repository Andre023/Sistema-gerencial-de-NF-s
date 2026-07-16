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
        'atendida_por',
        'loja',
        'motivo',
        'observacao',
        'status',
        'atendida_em',
    ];

    protected $casts = [
        'loja'        => 'integer',
        'atendida_em' => 'datetime',
    ];

    // Lojas válidas no sistema
    public const LOJAS = [1, 2, 3, 9, 11, 12];

    // Motivos válidos
    public const MOTIVOS = ['Cadastro', 'Preço', 'Regra', 'Quantidade', 'Pedido'];

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

    public function atendidaPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }

    public function auditorias(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequisicaoAuditoria::class);
    }

    public function comentarios(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comentario::class, 'comentavel');
    }

    /**
     * Indica se a requisição veio de um dia anterior à data consultada
     */
    public function isAtrasada(string $dataFiltro): bool
    {
        return $this->created_at->toDateString() < $dataFiltro;
    }
}
