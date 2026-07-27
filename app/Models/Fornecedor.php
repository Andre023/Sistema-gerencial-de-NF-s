<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'cnpj', 'prioridade'];

    protected $casts = [
        'prioridade' => 'boolean',
    ];

    public function notas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Nota::class);
    }
}
