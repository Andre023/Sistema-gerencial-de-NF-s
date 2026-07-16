<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Card;
use App\Models\Nota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ciclo do card de divergência:
 *
 *   abrir (pré-lote) → corrigir (compras) → resolver (pré-lote)
 *                       ↑______ reabrir (pré-lote), se ainda estiver errado
 */
class CardController extends Controller
{
    // ─── ABRIR ────────────────────────────────────────────────────────────────

    public function store(Request $request, Nota $nota): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        if ($nota->liberada_em) {
            return back()->withErrors(['card' => 'A nota já foi liberada — não é possível abrir divergência.']);
        }

        $dados = $request->validate([
            'tipo'    => ['required', Rule::in(Card::TIPOS)],
            'detalhe' => 'nullable|string|max:500',
        ]);

        // Um card ativo por tipo: se cadastro já está aberto, não abre outro igual
        $jaExiste = $nota->cards()
            ->where('tipo', $dados['tipo'])
            ->where('status', '!=', Card::STATUS_RESOLVIDO)
            ->exists();

        if ($jaExiste) {
            return back()->withErrors(['tipo' => 'Já existe um card de ' . $dados['tipo'] . ' em aberto nesta nota.']);
        }

        $nota->cards()->create([
            'tipo'       => $dados['tipo'],
            'detalhe'    => $dados['detalhe'] ?? null,
            'status'     => Card::STATUS_ABERTO,
            'aberto_por' => $request->user()->id,
        ]);

        event(new NotaAtualizada());

        return back()->with('sucesso', 'Divergência registrada.');
    }

    // ─── CORRIGIR (compras marca que arrumou no ERP) ──────────────────────────

    public function corrigir(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('corrigir-card');

        $this->garanteVinculo($nota, $card);

        if ($card->status !== Card::STATUS_ABERTO) {
            return back()->withErrors(['card' => 'Este card não está aberto.']);
        }

        $card->update([
            'status'        => Card::STATUS_CORRIGIDO,
            'corrigido_por' => $request->user()->id,
            'corrigido_em'  => now(),
        ]);

        // O broadcast é a notificação: a tela do pré-lote atualiza na hora
        event(new NotaAtualizada());

        return back()->with('sucesso', 'Card marcado como corrigido — aguardando reconferência do pré-lote.');
    }

    // ─── RESOLVER (pré-lote reconfere e fecha) ────────────────────────────────

    public function resolver(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        $this->garanteVinculo($nota, $card);

        if ($card->status === Card::STATUS_RESOLVIDO) {
            return back()->withErrors(['card' => 'Este card já foi resolvido.']);
        }

        $card->update([
            'status'        => Card::STATUS_RESOLVIDO,
            'resolvido_por' => $request->user()->id,
            'resolvido_em'  => now(),
        ]);

        event(new NotaAtualizada());

        return back()->with('sucesso', 'Card resolvido.');
    }

    // ─── REABRIR (reconferiu e ainda está errado) ─────────────────────────────

    public function reabrir(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        $this->garanteVinculo($nota, $card);

        if ($card->status !== Card::STATUS_CORRIGIDO) {
            return back()->withErrors(['card' => 'Só é possível reabrir um card que está aguardando reconferência.']);
        }

        $card->update([
            'status'        => Card::STATUS_ABERTO,
            'corrigido_por' => null,
            'corrigido_em'  => null,
            'reaberturas'   => $card->reaberturas + 1,
        ]);

        event(new NotaAtualizada());

        return back()->with('sucesso', 'Card reaberto — voltou para a fila de compras.');
    }

    // ─── EXCLUIR (aberto por engano) ──────────────────────────────────────────

    public function destroy(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        $this->garanteVinculo($nota, $card);

        $card->delete();

        event(new NotaAtualizada());

        return back()->with('sucesso', 'Card removido.');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** Garante que o card pertence mesmo à nota da URL. */
    private function garanteVinculo(Nota $nota, Card $card): void
    {
        abort_if($card->nota_id !== $nota->id, 404);
    }
}
