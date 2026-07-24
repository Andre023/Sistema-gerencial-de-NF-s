<?php

namespace App\Http\Controllers;

use App\Events\NotificacoesAtualizadas;
use App\Models\Notificacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * O sino. A lista em si vai nos props compartilhados (HandleInertiaRequests) e
 * é mantida ao vivo pelo NotificacoesAtualizadas — aqui ficam só as ações.
 */
class NotificacaoController extends Controller
{
    /** Clique no aviso: marca como lido e cai na nota. */
    public function abrir(Request $request, Notificacao $notificacao): RedirectResponse
    {
        $this->garanteDono($request, $notificacao);

        $notificacao->update(['lida_em' => now()]);

        event(new NotificacoesAtualizadas($request->user()));

        $nota = $notificacao->nota;

        if (! $nota) {
            return redirect()->route('notas.index');
        }

        // A fila arrasta as notas em aberto para o dia de hoje, mas as liberadas
        // só aparecem no dia exato da liberação — por isso a data muda conforme.
        $data = $nota->liberada_em
            ? $nota->liberada_em->toDateString()
            : Carbon::today()->toDateString();

        return redirect()->route('notas.index', [
            'busca' => $nota->numero_nota,
            'data'  => $data,
        ]);
    }

    /** "Limpar" o sino sem abrir uma por uma. */
    public function lerTodas(Request $request): RedirectResponse
    {
        Notificacao::where('user_id', $request->user()->id)
            ->pendentes()
            ->update(['lida_em' => now()]);

        event(new NotificacoesAtualizadas($request->user()));

        return back();
    }

    private function garanteDono(Request $request, Notificacao $notificacao): void
    {
        abort_if($notificacao->user_id !== $request->user()->id, 404);
    }
}
