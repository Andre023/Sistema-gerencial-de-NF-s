<?php

namespace App\Http\Controllers;

use App\Events\RequisicaoAtualizada;
use App\Models\Comentario;
use App\Models\Requisicao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComentarioController extends Controller
{
    /**
     * Linha do tempo da requisição: auditoria + comentários, em ordem cronológica.
     * Responde JSON — o modal busca sob demanda, então a listagem não carrega
     * thread nenhuma (só o contador).
     */
    public function index(Request $request, Requisicao $requisicao): JsonResponse
    {
        return response()->json([
            'timeline' => $this->timeline($requisicao, $request->user()),
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request, Requisicao $requisicao): JsonResponse
    {
        // Comentar é liberado a todos os papéis — é justamente o canal do operador,
        // que não pode editar os campos do registro.
        $dados = $request->validate([
            'texto' => 'required|string|max:1000',
        ]);

        $requisicao->comentarios()->create([
            'user_id' => $request->user()->id,
            'texto'   => $dados['texto'],
        ]);

        // Atualiza o contador na lista dos outros usuários
        event(new RequisicaoAtualizada());

        return response()->json([
            'timeline' => $this->timeline($requisicao->fresh(), $request->user()),
        ], 201);
    }

    // ─── DESTROY ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, Requisicao $requisicao, Comentario $comentario): JsonResponse
    {
        // Garante que o comentário é mesmo desta requisição (evita id de outra thread)
        if (
            $comentario->comentavel_type !== Requisicao::class ||
            $comentario->comentavel_id !== $requisicao->id
        ) {
            abort(404);
        }

        if (! $comentario->podeSerExcluidoPor($request->user())) {
            abort(403);
        }

        $comentario->delete();

        event(new RequisicaoAtualizada());

        return response()->json([
            'timeline' => $this->timeline($requisicao->fresh(), $request->user()),
        ]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** Rótulo humano para cada ação da auditoria. */
    private const ACAO_LABEL = [
        'criada'   => 'criou a requisição',
        'editada'  => 'editou',
        'atendida' => 'atendeu',
        'excluida' => 'excluiu',
    ];

    private function timeline(Requisicao $requisicao, \App\Models\User $atual): array
    {
        $eventos = $requisicao->auditorias()->with('user:id,name')->get()
            ->map(fn($a) => [
                'tipo'    => 'evento',
                'id'      => 'a' . $a->id,
                'acao'    => self::ACAO_LABEL[$a->acao] ?? $a->acao,
                'usuario' => $a->user->name ?? '—',
                'em'      => $a->criado_em,
            ]);

        $comentarios = $requisicao->comentarios()->with('user:id,name')->get()
            ->map(fn($c) => [
                'tipo'         => 'comentario',
                'id'           => $c->id,
                'texto'        => $c->texto,
                'usuario'      => $c->user->name ?? '—',
                'usuario_id'   => $c->user_id,
                'em'           => $c->created_at,
                'pode_excluir' => $c->podeSerExcluidoPor($atual),
            ]);

        return $eventos->concat($comentarios)
            ->sortBy('em')
            ->values()
            ->all();
    }
}
