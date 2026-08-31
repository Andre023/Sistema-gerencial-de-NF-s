<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Comentario;
use App\Models\Nota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ComentarioController extends Controller
{
    /**
     * A conversa da nota — só o que foi escrito por gente.
     *
     * Até aqui esta thread também mostrava eventos deduzidos ("abriu custo",
     * "corrigiu custo"), e eles atrapalhavam das duas pontas: enterravam o
     * recado de verdade no meio do relatório, e mesmo assim contavam só metade
     * da história — o que a dedução alcançava. Quem quer a história agora tem
     * o livro de ocorrências (OcorrenciaController), que registra na hora da
     * ação e por isso enxerga também o que foi editado e apagado.
     */
    public function index(Request $request, Nota $nota): JsonResponse
    {
        return response()->json([
            'comentarios' => $this->thread($nota, $request->user()),
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request, Nota $nota): JsonResponse
    {
        // Comentar é o canal de contexto entre recebimento, pré-lote e compras.
        // Liberado a todos os papéis operacionais; o visitante é só leitura.
        Gate::authorize('interagir');

        $dados = $request->validate([
            'texto' => 'required|string|max:1000',
        ]);

        $nota->comentarios()->create([
            'user_id' => $request->user()->id,
            'texto'   => $dados['texto'],
        ]);

        event(new NotaAtualizada($nota));

        return response()->json([
            'comentarios' => $this->thread($nota->fresh(), $request->user()),
            // A nota vai junto para a fila atualizar só esta linha: o que
            // mudou nela foi o contador do botão. Antes a tela recarregava
            // as listas inteiras (~166 KB) por causa de um número.
            'nota'        => $nota->paraTelaAgora(),
        ], 201);
    }

    // ─── DESTROY ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, Nota $nota, Comentario $comentario): JsonResponse
    {
        if (
            $comentario->comentavel_type !== Nota::class ||
            $comentario->comentavel_id !== $nota->id
        ) {
            abort(404);
        }

        if (! $comentario->podeSerExcluidoPor($request->user())) {
            abort(403);
        }

        $comentario->delete();

        event(new NotaAtualizada($nota));

        return response()->json([
            'comentarios' => $this->thread($nota->fresh(), $request->user()),
            // A nota vai junto para a fila atualizar só esta linha: o que
            // mudou nela foi o contador do botão. Antes a tela recarregava
            // as listas inteiras (~166 KB) por causa de um número.
            'nota'        => $nota->paraTelaAgora(),
        ]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> os comentários da nota, do mais antigo ao mais novo */
    private function thread(Nota $nota, \App\Models\User $atual): array
    {
        return $nota->comentarios()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn(Comentario $c) => [
                'id'           => $c->id,
                'texto'        => $c->texto,
                'usuario'      => $c->user->name ?? '—',
                'usuario_id'   => $c->user_id,
                'em'           => $c->created_at,
                'pode_excluir' => $c->podeSerExcluidoPor($atual),
            ])
            ->all();
    }
}
