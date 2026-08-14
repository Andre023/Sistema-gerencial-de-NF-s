<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Card;
use App\Models\Nota;
use App\Services\Notificador;
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
        // Em geral só o pré-lote abre card. Exceções: nota de CEASA (compras
        // também abre) e os tipos de todo mundo (Importar NF, Trocar nota,
        // Recusa, Devolução) — que qualquer papel operacional abre, porque não
        // são erro de um setor. Quem FECHA cada um é outra história, e mora em
        // Card::podeSerCorrigidoPor().
        $user = $request->user();
        $deTodos = in_array($request->input('tipo'), Card::abertosPorQualquerPapel(), true);
        $podeAbrir = $user->podeGerirCards()
            || ($nota->ceasa && $user->podeCorrigirCard())
            || ($deTodos && ($user->podeLancarNota() || $user->podeCorrigirCard()));
        abort_unless($podeAbrir, 403);

        if ($nota->liberada_em) {
            return back()->withErrors(['card' => 'A nota já foi liberada — não é possível abrir divergência.']);
        }

        $dados = $request->validate([
            'tipo'    => ['required', Rule::in(Card::TIPOS)],
            'detalhe' => 'nullable|string|max:500',
        ]);

        // "Reconferir" é pedido de nova conferência do CEASA — não existe fora dele
        if (in_array($dados['tipo'], Card::TIPOS_SO_CEASA, true) && ! $nota->ceasa) {
            return back()->withErrors(['tipo' => 'O card de reconferência é exclusivo de notas de CEASA.']);
        }

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

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        event(new NotaAtualizada($nota));
        Notificador::cardAberto($nota, $request->user());

        return back()->with('sucesso', 'Divergência registrada.');
    }

    // ─── CORRIGIR (compras arruma no ERP → card resolvido, some da fila) ───────

    public function corrigir(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        $this->garanteVinculo($nota, $card);

        // Autorização por card (podeSerCorrigidoPor): compras corrige os tipos dela
        // (cadastro/custo/quantidade/sem_pedido); "Importar NF" é marcado por
        // recebimento, pré-lote ou compras; regra é do pré-lote; admin tudo.
        if (! $card->podeSerCorrigidoPor($request->user())) {
            abort(403, 'Você não pode marcar este card como corrigido.');
        }

        if ($card->status !== Card::STATUS_ABERTO) {
            return back()->withErrors(['card' => 'Este card não está aberto.']);
        }

        /*
         * Corrigir cadastro exige DIZER o que ficou pendente.
         *
         * O item que não existia passa a existir — mas existir não é estar no
         * pedido. Em geral sobra uma destas duas: ou não há pedido nenhum
         * (sem_pedido), ou há e o item não está nele (item_n_pedido). Às vezes
         * não sobra nada, e aí é só reconferir ('nenhum').
         *
         * O campo é obrigatório mesmo tendo a saída do 'nenhum': o que se quer
         * evitar não é a ausência de troca, é corrigir no automático sem olhar.
         * Antes, o cadastro era corrigido e a pendência real só aparecia quando
         * alguém tropeçava nela na conferência.
         */
        $substituto = null;

        if ($card->tipo === 'cadastro') {
            $request->validate([
                'substituto' => ['required', Rule::in(Card::escolhasDeCadastro())],
            ], [
                'substituto.required' => 'Diga o que ficou pendente depois do cadastro.',
                'substituto.in'       => 'Escolha "Item n pedido", "Sem pedido" ou "Só conferir".',
            ]);

            $escolha = $request->input('substituto');

            // 'nenhum' é resposta válida, e não um substituto: o card se resolve
            // e nada novo nasce.
            $substituto = $escolha === Card::SEM_TROCA ? null : $escolha;
        }

        // Corrigir já resolve: sem passo de confirmação por card. corrigido_por
        // registra que a resolução veio de compras (a conferência final é a liberação).
        $card->update([
            'status'        => Card::STATUS_RESOLVIDO,
            'corrigido_por' => $request->user()->id,
            'corrigido_em'  => now(),
        ]);

        // Um card ativo por tipo (mesma regra do store): se o substituto já
        // estiver aberto nesta nota, a troca não duplica — o cadastro se resolve
        // e a pendência que já existia continua valendo por si.
        $trocou = false;

        if ($substituto) {
            $jaAberto = $nota->cards()
                ->where('tipo', $substituto)
                ->where('status', '!=', Card::STATUS_RESOLVIDO)
                ->exists();

            if (! $jaAberto) {
                $nota->cards()->create([
                    'tipo'       => $substituto,
                    'status'     => Card::STATUS_ABERTO,
                    'aberto_por' => $request->user()->id,
                ]);

                $trocou = true;
            }
        }

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        // O broadcast atualiza a fila de quem está com a tela aberta; a
        // notificação é o que alcança quem não está olhando agora.
        event(new NotaAtualizada($nota));
        Notificador::cardCorrigido($nota, $card, $request->user());

        // O card novo é pendência nova: quem cuida dele precisa ser avisado.
        // Vai depois do cardCorrigido de propósito — aquele reduz o aviso ao
        // que sobrou, e este volta a acender com o que acabou de entrar.
        if ($trocou) {
            Notificador::cardAberto($nota->fresh('cards'), $request->user());
        }

        return back()->with('sucesso', $trocou
            ? 'Cadastro corrigido e trocado por ' . Card::rotulo($substituto) . '.'
            : 'Card corrigido.');
    }

    // ─── RESOLVER (pré-lote fecha direto — principalmente cards de regra) ──────

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

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        event(new NotaAtualizada($nota));
        Notificador::cardResolvido($nota, $request->user());

        return back()->with('sucesso', 'Card resolvido.');
    }

    // ─── REABRIR (na conferência final o pré-lote achou que ainda está errado) ─

    public function reabrir(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        $this->garanteVinculo($nota, $card);

        if ($card->status !== Card::STATUS_RESOLVIDO) {
            return back()->withErrors(['card' => 'Só é possível reabrir um card já resolvido.']);
        }

        $card->update([
            'status'        => Card::STATUS_ABERTO,
            'corrigido_por' => null,
            'corrigido_em'  => null,
            'resolvido_por' => null,
            'resolvido_em'  => null,
            'reaberturas'   => $card->reaberturas + 1,
        ]);

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        event(new NotaAtualizada($nota));
        Notificador::cardReaberto($nota, $request->user());

        return back()->with('sucesso', 'Card reaberto.');
    }

    // ─── EXCLUIR (aberto por engano) ──────────────────────────────────────────

    public function destroy(Request $request, Nota $nota, Card $card): RedirectResponse
    {
        Gate::authorize('gerir-cards');

        $this->garanteVinculo($nota, $card);

        $card->delete();

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        event(new NotaAtualizada($nota));
        // Card aberto por engano: se era o único de compras, o aviso deles some
        Notificador::cardResolvido($nota, $request->user());

        return back()->with('sucesso', 'Card removido.');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** Garante que o card pertence mesmo à nota da URL. */
    private function garanteVinculo(Nota $nota, Card $card): void
    {
        abort_if($card->nota_id !== $nota->id, 404);
    }
}
