<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PrioridadeController extends Controller
{
    /**
     * Aba Prioridades (só admin). Mostra os fornecedores já marcados e, quando o
     * admin busca, os resultados com o estado de prioridade de cada um — sem
     * despejar os ~2.700 fornecedores na tela.
     */
    public function index(Request $request): Response
    {
        $busca = trim((string) $request->input('busca', ''));

        $prioritarios = Fornecedor::where('prioridade', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'cnpj', 'prioridade']);

        $resultados = $busca !== ''
            ? Fornecedor::where('nome', 'like', "%{$busca}%")
                ->orderBy('nome')
                ->limit(30)
                ->get(['id', 'nome', 'cnpj', 'prioridade'])
            : new Collection();

        return Inertia::render('Prioridades/Index', [
            'prioritarios' => $prioritarios,
            'resultados'   => $resultados,
            'busca'        => $busca,
        ]);
    }

    /**
     * Liga/desliga a prioridade de um fornecedor. Idempotente: recebe o alvo
     * (true/false) em vez de "inverter", pra dois cliques rápidos não brigarem.
     */
    public function alternar(Request $request, Fornecedor $fornecedor): RedirectResponse
    {
        $dados = $request->validate([
            'prioridade' => ['required', 'boolean'],
        ]);

        $fornecedor->update($dados);

        return back(); // Inertia recarrega a página mantendo a busca atual
    }
}
