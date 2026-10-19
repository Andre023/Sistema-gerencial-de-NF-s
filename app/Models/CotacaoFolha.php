<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma planilha da cotação — o que a tela mostra como uma aba editável.
 *
 * Formato (o mesmo que a tela usa, ver resources/js/lib/cotacao.ts):
 *
 *   colunas: [{id, nome, tipo: texto|numero|moeda, largura, fixa?, estilo?}]
 *   linhas:  [{id, k?, v: {coluna: valor}, s?: estilo, e?: {coluna: estilo}}]
 *
 * `k` é a identidade do produto (GTIN sem zeros à esquerda ou, sem GTIN, a
 * descrição) — é por ela que gerar de novo a prévia ou a planilha de compra
 * reencontra a linha e não apaga o que o comprador já escreveu ali.
 */
class CotacaoFolha extends Model
{
    protected $table = 'cotacao_folhas';

    public const TIPOS = ['lista', 'previa', 'resposta', 'compra'];

    protected $fillable = ['tipo', 'nome', 'arquivo', 'meta', 'colunas', 'linhas', 'versao', 'ordem'];

    // Os padrões da tabela também no modelo: a folha recém-criada já sai com a
    // versão no aviso de tempo real, sem ir ao banco buscar.
    protected $attributes = ['versao' => 0, 'ordem' => 0];

    protected $casts = [
        'meta'    => 'array',
        'colunas' => 'array',
        'linhas'  => 'array',
        'versao'  => 'integer',
        'ordem'   => 'integer',
    ];

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }

    /** O que a tela recebe. */
    public function paraTela(): array
    {
        return [
            'id'      => $this->id,
            'tipo'    => $this->tipo,
            'nome'    => $this->nome,
            'arquivo' => $this->arquivo,
            'meta'    => (object) ($this->meta ?? []),
            'colunas' => $this->colunas ?? [],
            'linhas'  => $this->linhas ?? [],
            'versao'  => $this->versao,
        ];
    }
}
