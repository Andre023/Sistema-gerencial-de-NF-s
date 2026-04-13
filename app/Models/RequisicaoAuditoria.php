<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisicaoAuditoria extends Model
{
    public $timestamps = false;

    protected $table = 'requisicao_auditorias';

    protected $fillable = [
        'requisicao_id',
        'user_id',
        'acao',
        'dados_anteriores',
        'dados_novos',
        'criado_em',
    ];

    protected $casts = [
        'dados_anteriores' => 'array',
        'dados_novos'      => 'array',
        'criado_em'        => 'datetime',
    ];

    public function requisicao(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Requisicao::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
