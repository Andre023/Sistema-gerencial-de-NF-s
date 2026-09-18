<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Consignados: quais fornecedores trabalham em consignação.
 *
 * É uma marca do FORNECEDOR, e não da nota — é isso que a separa do CEASA.
 * O CEASA precisa ser marcado a cada nota lançada; aqui a pessoa marca o
 * fornecedor uma vez e toda nota dele, passada e futura, leva o selo antes
 * do nome na fila. Ninguém precisa lembrar de nada na hora de lançar.
 *
 * O desenho é o mesmo de Prioridades: os já marcados em lista, e a busca
 * (no servidor, sem despejar os ~2.800 nomes) para achar quem falta.
 */
class ConsignadoController extends Controller
{
    /** A lista é de qualquer conta — inclusive o visitante, que só olha. */
    public function index(Request $request): Response
    {
        $busca = trim((string) $request->input('busca', ''));

        $consignados = Fornecedor::where('consignado', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'cnpj', 'consignado']);

        // Só matrizes: a marca é da matriz, e o nome da filial acha a matriz.
        $resultados = $busca !== ''
            ? Fornecedor::matrizes()->comNome($busca)
                ->orderBy('nome')
                ->limit(30)
                ->get(['id', 'nome', 'cnpj', 'consignado'])
            : new Collection();

        return Inertia::render('Configuracoes/Consignados', [
            'consignados' => $consignados,
            'resultados'  => $resultados,
            'busca'       => $busca,
        ]);
    }

    /**
     * Liga/desliga a marca de um fornecedor. Idempotente: recebe o alvo
     * (true/false) em vez de "inverter", pra dois cliques rápidos não brigarem.
     * O Gate (marcar-consignados) está na rota.
     */
    public function alternar(Request $request, Fornecedor $fornecedor): RedirectResponse
    {
        $dados = $request->validate([
            'consignado' => ['required', 'boolean'],
        ]);

        $fornecedor->update($dados);

        return back(); // Inertia recarrega a página mantendo a busca atual
    }
}
