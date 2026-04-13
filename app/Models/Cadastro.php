<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cadastro extends Model
{
    use SoftDeletes;

    protected $table = 'cadastros';

    protected $fillable = [
        'numero_nota',
        'fornecedor_id',
        'user_id',
        'requisicao_id',
        'loja',
        'motivo',
        'observacao',
        'status',
    ];

    protected $casts = [
        'loja' => 'integer',
    ];

    public const LOJAS = [1, 2, 3, 9, 11, 12];

    public const MOTIVOS = ['Pré Lote', 'Caminhão na Porta'];

    public const STATUS = ['Pendente', 'Atendida'];

    public function fornecedor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requisicao(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Requisicao::class);
    }

    public function isAtrasada(string $dataFiltro): bool
    {
        return $this->created_at->toDateString() < $dataFiltro;
    }
}
