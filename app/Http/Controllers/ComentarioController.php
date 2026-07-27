<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Card;
use App\Models\Comentario;
use App\Models\Nota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ComentarioController extends Controller
{
    /**
     * Linha do tempo da nota: criação, ciclo dos cards, liberação e comentários,
     * em ordem cronológica. Os eventos nascem dos próprios dados — não há tabela
     * de auditoria para dessincronizar.
     */
    public function index(Request $request, Nota $nota): JsonResponse
    {
        return response()->json([
            'timeline' => $this->timeline($nota, $request->user()),
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
            'timeline' => $this->timeline($nota->fresh(), $request->user()),
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
            'timeline' => $this->timeline($nota->fresh(), $request->user()),
        ]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function timeline(Nota $nota, \App\Models\User $atual): array
    {
        $nota->load([
            'user:id,name', 'liberadaPor:id,name',
            'cards.abertoPor:id,name', 'cards.corrigidoPor:id,name', 'cards.resolvidoPor:id,name',
        ]);

        $eventos = collect([[
            'tipo'    => 'evento',
            'id'      => 'criada',
            'acao'    => 'lançou a nota',
            'usuario' => $nota->user->name ?? '—',
            'em'      => $nota->created_at,
        ]]);

        foreach ($nota->cards as $card) {
            $eventos->push([
                'tipo'    => 'evento',
                'id'      => "card{$card->id}-aberto",
                'acao'    => "abriu divergência de {$card->tipo}",
                'usuario' => $card->abertoPor->name ?? '—',
                'em'      => $card->created_at,
            ]);

            $sufixoReab = $card->reaberturas > 0
                ? " (após {$card->reaberturas} reabertura" . ($card->reaberturas > 1 ? 's' : '') . ')'
                : '';

            // Compras corrigindo no ERP já resolve o card
            if ($card->corrigido_em) {
                $eventos->push([
                    'tipo'    => 'evento',
                    'id'      => "card{$card->id}-corrigido",
                    'acao'    => "corrigiu {$card->tipo}{$sufixoReab}",
                    'usuario' => $card->corrigidoPor->name ?? '—',
                    'em'      => $card->corrigido_em,
                ]);
            }

            // Pré-lote resolvendo direto (ex.: regra)
            if ($card->resolvido_em) {
                $eventos->push([
                    'tipo'    => 'evento',
                    'id'      => "card{$card->id}-resolvido",
                    'acao'    => "resolveu {$card->tipo}{$sufixoReab}",
                    'usuario' => $card->resolvidoPor->name ?? '—',
                    'em'      => $card->resolvido_em,
                ]);
            }
        }

        if ($nota->liberada_em) {
            $eventos->push([
                'tipo'    => 'evento',
                'id'      => 'liberada',
                'acao'    => 'liberou a nota',
                'usuario' => $nota->liberadaPor->name ?? '—',
                'em'      => $nota->liberada_em,
            ]);
        }

        $comentarios = $nota->comentarios()->with('user:id,name')->get()
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
