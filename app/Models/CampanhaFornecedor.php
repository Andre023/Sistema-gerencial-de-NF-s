<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha do ranking de compras — o fornecedor e o quanto a loja comprou dele
 * nos últimos 12 meses.
 *
 * É a base que preenche sozinha o faturamento na tela da campanha. Vive de
 * planilha: cada envio troca a tabela inteira (ver CampanhaController::
 * importarPlanilha), então não há "editar" nem "excluir" um por um.
 */
class CampanhaFornecedor extends Model
{
    protected $table = 'campanha_fornecedores';

    public $timestamps = false;

    protected $fillable = ['nome', 'chave', 'faturamento'];

    protected $casts = [
        'faturamento' => 'decimal:2',
    ];

    /**
     * A forma comparável de um nome: maiúsculas, sem acento, sem pontuação
     * dobrada e com um espaço só entre palavras.
     *
     * Serve para reconhecer o fornecedor quando o comprador digita o nome em
     * vez de escolher na lista — "Vilma Alimentos" e "VILMA  ALIMENTOS" são o
     * mesmo parceiro.
     */
    public static function chaveDe(string $nome): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        $sem = $sem === false ? $nome : $sem;

        // O //TRANSLIT do iconv devolve coisas como "c'" para ç em alguns
        // sistemas; sobra só letra, número e espaço.
        $sem = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $sem) ?? $nome;

        return trim(preg_replace('/\s+/', ' ', mb_strtoupper($sem)) ?? $sem);
    }
}
