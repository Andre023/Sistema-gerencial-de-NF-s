<?php

namespace App\Models;

use App\Models\Concerns\TemIdade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cadastro extends Model
{
    use SoftDeletes, TemIdade;

    protected $table = 'cadastros';

    protected $fillable = [
        'numero_nota',
        'fornecedor_id',
        'user_id',
        'atendida_por',
        'requisicao_id',
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

    public function atendidaPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }

    public function requisicao(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Requisicao::class);
    }

    public function comentarios(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comentario::class, 'comentavel');
    }

}
