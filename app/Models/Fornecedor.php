<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'cnpj'];

    public function requisicoes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Requisicao::class);
    }
}
