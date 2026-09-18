<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Ocorrencia;
use App\Services\Ocorrencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Matriz/Filial: juntar as duas metades de um mesmo fornecedor.
 *
 * O mesmo fornecedor entra duas vezes na base — a matriz e a filial, com
 * CNPJs diferentes, ou "S.A." e "S/A" — e cada uma leva parte do histórico.
 * Aqui uma vira filial da outra: as notas da filial passam para a matriz, e
 * o nome da filial continua achando a matriz em toda busca.
 *
 * Nada é apagado. As notas movidas ganham ocorrência (FORNECEDOR_UNIFICADO)
 * com os dois nomes, e desfazer o vínculo devolve a filial como cadastro
 * próprio — sem as notas antigas, que ficam na matriz.
 */
class FornecedorVinculoController extends Controller
{
    private const LIMITE_BUSCA = 40;

    public function index(): Response
    {
        return Inertia::render('Configuracoes/MatrizFilial', [
            'vinculos' => $this->vinculos(),
            'total'    => Fornecedor::count(),
        ]);
    }

    /**
     * Busca por nome. Devolve matrizes e filiais, cada uma dizendo o que é —
     * quem procura "MOINHO" precisa ver as duas grafias lado a lado para
     * escolher qual vira filial de qual.
     */
    public function buscar(Request $request): JsonResponse
    {
        $termo = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '');

        if ($termo === '') {
            return response()->json(['fornecedores' => [], 'truncada' => false]);
        }

        $lista = Fornecedor::where('nome', 'like', "%{$termo}%")
            ->with(['matriz:id,nome', 'filiais:id,nome,matriz_id'])
            ->withCount('notas')
            ->orderBy('nome')
            ->limit(self::LIMITE_BUSCA)
            ->get()
            ->map(fn(Fornecedor $f) => $this->paraTela($f));

        return response()->json([
            'fornecedores' => $lista,
            'truncada'     => $lista->count() === self::LIMITE_BUSCA,
        ]);
    }

    /** {filial} passa a ser filial de `matriz_id`. */
    public function vincular(Request $request, Fornecedor $filial): JsonResponse
    {
        $dados = $request->validate([
            'matriz_id' => ['required', 'integer', 'exists:fornecedores,id'],
        ]);

        $matriz = Fornecedor::findOrFail($dados['matriz_id']);

        if ($matriz->id === $filial->id) {
            return response()->json(['erro' => 'Um fornecedor não pode ser filial de si mesmo.'], 422);
        }

        if ($matriz->ehFilial()) {
            return response()->json(['erro' => sprintf(
                '"%s" já é filial de "%s". Vincule direto à matriz.',
                $matriz->nome, $matriz->matriz->nome,
            )], 422);
        }

        if ($filial->matriz_id === $matriz->id) {
            return response()->json(['erro' => 'Este vínculo já existe.'], 422);
        }

        $movidas = 0;

        DB::transaction(function () use ($filial, $matriz, &$movidas) {
            // Se a "filial" tinha filiais, elas vêm junto: são todas o mesmo fornecedor.
            Fornecedor::where('matriz_id', $filial->id)->update(['matriz_id' => $matriz->id]);

            // A prioridade é do fornecedor, e o fornecedor agora é a matriz.
            if ($filial->prioridade && ! $matriz->prioridade) {
                $matriz->update(['prioridade' => true]);
            }
            $filial->update(['matriz_id' => $matriz->id, 'prioridade' => false]);

            $movidas = $this->moverNotas($filial, $matriz);
        });

        if ($movidas > 0) {
            rescue(fn() => event(new NotaAtualizada()));
        }

        return response()->json([
            'ok'      => true,
            'movidas' => $movidas,
            'matriz'  => $this->paraTela($matriz->fresh(['matriz', 'filiais'])->loadCount('notas')),
        ]);
    }

    /**
     * Desfaz o vínculo. A filial volta a ser cadastro próprio, sem as notas
     * antigas — elas ficam na matriz, com a ocorrência que diz de onde vieram.
     */
    public function desvincular(Fornecedor $filial): JsonResponse
    {
        if (! $filial->ehFilial()) {
            return response()->json(['erro' => 'Este fornecedor não é filial de ninguém.'], 422);
        }

        $filial->update(['matriz_id' => null]);

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Uma a uma, e não num UPDATE só: é o NotaObserver que escreve a
     * ocorrência, e ele só vê o que passa pelo modelo. Inclui as excluídas —
     * a lixeira também precisa apontar para o fornecedor certo.
     */
    private function moverNotas(Fornecedor $de, Fornecedor $para): int
    {
        $movidas = 0;

        // eachById, não each: cada nota atualizada sai do WHERE, e a paginação
        // por offset do each() pularia a metade seguinte.
        Nota::withTrashed()
            ->where('fornecedor_id', $de->id)
            ->eachById(function (Nota $nota) use ($de, $para, &$movidas) {
                Ocorrencias::intencao(Ocorrencia::FORNECEDOR_UNIFICADO, ['de' => $de->nome, 'para' => $para->nome]);
                $nota->update(['fornecedor_id' => $para->id]);
                $movidas++;
            });

        return $movidas;
    }

    /** Todos os vínculos, agrupados por matriz, para a lista da tela. */
    private function vinculos(): array
    {
        return Fornecedor::whereHas('filiais')
            ->with('filiais:id,nome,matriz_id')
            ->withCount('notas')
            ->orderBy('nome')
            ->get()
            ->map(fn(Fornecedor $f) => [
                'id'      => $f->id,
                'nome'    => $f->nome,
                'notas'   => $f->notas_count,
                'filiais' => $f->filiais->map(fn($x) => ['id' => $x->id, 'nome' => $x->nome])->values(),
            ])
            ->values()
            ->all();
    }

    private function paraTela(Fornecedor $f): array
    {
        return [
            'id'      => $f->id,
            'nome'    => $f->nome,
            'notas'   => $f->notas_count ?? $f->notas()->count(),
            'matriz'  => $f->matriz ? ['id' => $f->matriz->id, 'nome' => $f->matriz->nome] : null,
            'filiais' => $f->filiais->map(fn($x) => ['id' => $x->id, 'nome' => $x->nome])->values(),
        ];
    }
}
