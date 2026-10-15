<?php

namespace App\Http\Controllers;

use App\Models\Mensagem;
use App\Models\User;
use App\Services\Novidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Novidades: o admin escreve, o Hipermon conta para todo mundo
 * no chat (App\Services\Novidades). Só admin — o Gate está na rota.
 */
class NovidadeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracoes/Novidades', [
            'hipermon'   => User::hipermon()?->only(['id', 'name', 'avatar']),
            'pessoas'    => User::where('role', '!=', User::ROLE_SISTEMA)->count(),
            'historico'  => Novidades::historico(),
            'textoMax'   => Mensagem::TEXTO_MAX,
            'diasDeVida' => Mensagem::DIAS_DE_VIDA,
        ]);
    }

    public function publicar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'texto' => ['required', 'string', 'max:' . Mensagem::TEXTO_MAX],
        ], [
            'texto.required' => 'Escreva a novidade antes de enviar.',
        ]);

        $texto = trim($dados['texto']);

        abort_unless(User::hipermon(), 409, 'A conta do Hipermon ainda não existe.');

        $total = Novidades::publicar($texto);

        return back()->with('sucesso', "Novidade enviada para {$total} pessoa" . ($total === 1 ? '' : 's') . '.');
    }
}
