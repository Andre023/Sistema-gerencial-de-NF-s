<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Liga/desliga o sino. Só vale daqui para frente: os avisos que já chegaram
     * continuam na lista, senão desligar apagaria pendência real sem querer.
     */
    public function notificacoes(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'notificacoes_ativas' => ['required', 'boolean'],
        ]);

        $request->user()->update($dados);

        return Redirect::route('profile.edit');
    }

    /**
     * Salva o avatar do usuário: emoji (o valor é o emoji já com tom de pele) ou
     * monograma (o valor é a cor, ou null para a cor automática derivada do nome).
     */
    public function avatar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'tipo'  => ['required', 'in:emoji,monograma'],
            'valor' => ['nullable', 'string', 'max:32'],
        ]);

        $request->user()->update([
            'avatar_tipo'  => $dados['tipo'],
            'avatar_valor' => $dados['valor'] ?? null,
        ]);

        return Redirect::route('profile.edit');
    }

    /**
     * Apagar a própria conta.
     *
     * As mesmas travas da tela de Usuários valem aqui — senão esta é a porta
     * dos fundos: o único admin se apagava por conta própria e o sistema ficava
     * sem quem cria usuário; e quem já tinha lançado nota esbarrava na FK
     * restritiva depois do logout, com a conta num limbo.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($impedimento = $user->impedimentoParaExclusao()) {
            return back()->withErrors(['conta' => $impedimento]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
