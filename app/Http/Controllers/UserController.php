<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    // ─── INDEX ────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $usuarios = User::select('id', 'name', 'email', 'role', 'created_at')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'created_at' => $u->created_at,
            ]);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'papeis'   => User::ROLES,
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|lowercase|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role'     => ['required', Rule::in(User::ROLES)],
        ]);

        User::create([
            'name'              => $dados['name'],
            'email'             => $dados['email'],
            'password'          => Hash::make($dados['password']),
            'role'              => $dados['role'],
            'email_verified_at' => now(), // criado por um admin — já confiável
        ]);

        return back()->with('sucesso', 'Usuário criado com sucesso.');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function update(Request $request, User $user): RedirectResponse
    {
        $dados = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role'     => ['sometimes', Rule::in(User::ROLES)],
        ]);

        // Impede que o último admin perca o papel de admin (evita lockout)
        if (
            isset($dados['role']) &&
            $dados['role'] !== User::ROLE_ADMIN &&
            $user->isAdmin() &&
            User::where('role', User::ROLE_ADMIN)->count() <= 1
        ) {
            return back()->withErrors(['role' => 'Este é o único administrador — promova outro antes de rebaixá-lo.']);
        }

        $user->fill([
            'name'  => $dados['name']  ?? $user->name,
            'email' => $dados['email'] ?? $user->email,
            'role'  => $dados['role']  ?? $user->role,
        ]);

        if (!empty($dados['password'])) {
            $user->password = Hash::make($dados['password']);
        }

        $user->save();

        return back()->with('sucesso', 'Usuário atualizado.');
    }

    // ─── DESTROY ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['usuario' => 'Você não pode excluir a própria conta.']);
        }

        // Último admin e histórico de notas: a regra mora no model porque o
        // Perfil (a pessoa apagando a si mesma) precisa exatamente da mesma.
        if ($impedimento = $user->impedimentoParaExclusao()) {
            return back()->withErrors(['usuario' => $impedimento]);
        }

        $user->delete();

        return back()->with('sucesso', 'Usuário removido.');
    }
}
