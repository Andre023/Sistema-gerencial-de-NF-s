<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Consignados / Feira / Uso e consumo — as marcas de fornecedor.
 *
 * Três seções, um controller: o que muda entre elas é só a coluna
 * (Fornecedor::MARCAS). A URL diz qual é ({marca}), e a rota só aceita os
 * slugs conhecidos, então aqui dentro o slug é sempre válido.
 *
 * É uma marca do FORNECEDOR, e não da nota — é isso que a separa do CEASA.
 * O CEASA precisa ser marcado a cada nota lançada; aqui a pessoa marca o
 * fornecedor uma vez e toda nota dele, passada e futura, leva o selo junto
 * do número na fila. Ninguém precisa lembrar de nada na hora de lançar.
 *
 * O desenho é o mesmo de Prioridades: os já marcados em lista, e a busca
 * (no servidor, sem despejar os ~2.800 nomes) para achar quem falta.
 */
class MarcaFornecedorController extends Controller
{
    /** A lista é de qualquer conta — inclusive o visitante, que só olha. */
    public function index(Request $request, string $marca): Response
    {
        $coluna = Fornecedor::colunaDaMarca($marca);
        $busca  = trim((string) $request->input('busca', ''));
        $campos = ['id', 'nome', 'cnpj', $coluna];

        $marcados = Fornecedor::where($coluna, true)
            ->orderBy('nome')
            ->get($campos);

        // Só matrizes: a marca é da matriz, e o nome da filial acha a matriz.
        $resultados = $busca !== ''
            ? Fornecedor::matrizes()->comNome($busca)
                ->orderBy('nome')
                ->limit(30)
                ->get($campos)
            : new Collection();

        return Inertia::render('Configuracoes/Marca', [
            'marca'      => $marca,
            'marcados'   => $marcados,
            'resultados' => $resultados,
            'busca'      => $busca,
        ]);
    }

    /**
     * Liga/desliga a marca de um fornecedor. Idempotente: recebe o alvo
     * (true/false) em vez de "inverter", pra dois cliques rápidos não brigarem.
     * O Gate (marcar-fornecedores) está na rota.
     */
    public function alternar(Request $request, string $marca, Fornecedor $fornecedor): RedirectResponse
    {
        $dados = $request->validate([
            'marcado' => ['required', 'boolean'],
        ]);

        $fornecedor->update([Fornecedor::colunaDaMarca($marca) => $dados['marcado']]);

        return back(); // Inertia recarrega a página mantendo a busca atual
    }
}
