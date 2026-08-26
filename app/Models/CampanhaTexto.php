<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O esqueleto da carta salvo por um comprador — um por pessoa.
 *
 * Cada um negocia de um jeito, e o texto padrão é só o ponto de partida: quem
 * salva o seu abre a aba já com ele na tela, sem reescrever a cada fornecedor.
 */
class CampanhaTexto extends Model
{
    protected $table = 'campanha_textos';

    protected $fillable = ['user_id', 'texto'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
