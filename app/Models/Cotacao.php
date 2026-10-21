<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma rodada de cotação CEASA: do estoque baixo das lojas até o pedido.
 *
 * As planilhas em si moram nas folhas (CotacaoFolha). A cotação é só o
 * envelope — o título que aparece na lista e quem abriu.
 */
class Cotacao extends Model
{
    protected $table = 'cotacoes';

    protected $fillable = ['titulo', 'criada_por'];

    /** As duas lojas que fazem cotação na CEASA. */
    public const LOJAS = [
        'gb' => ['nome' => 'Gentil Bicalho', 'sigla' => 'GB'],
        'wa' => ['nome' => 'Wilson Alvarenga', 'sigla' => 'WA'],
    ];

    /** Os setores, na ordem em que aparecem na lista que vai ao fornecedor. */
    public const SETORES = [
        'merc'  => 'Mercearia',
        'beb'   => 'Bebidas',
        'frios' => 'Frios',
    ];

    public function folhas(): HasMany
    {
        return $this->hasMany(CotacaoFolha::class)->orderBy('ordem')->orderBy('id');
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }
}
