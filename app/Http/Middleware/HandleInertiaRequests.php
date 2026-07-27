<?php

namespace App\Http\Middleware;

use App\Services\Notificador;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Permissões derivadas da função — o frontend usa isto para mostrar/ocultar
                'can'  => $user ? [
                    'lancarNota'        => $user->podeLancarNota(),
                    'gerirCards'        => $user->podeGerirCards(),
                    'corrigirCard'      => $user->podeCorrigirCard(),
                    'liberarNota'       => $user->podeLiberarNota(),
                    'editarNotas'       => $user->podeEditarNotas(),
                    'devolverNota'      => $user->podeDevolverNota(),
                    'gerenciarNotas'    => $user->podeGerenciarNotas(),
                    'excluirNotaLiberada' => $user->podeExcluirNotaLiberada(),
                    'verEstatisticas'   => $user->podeVerEstatisticas(),
                    'gerenciarUsuarios' => $user->podeGerenciarUsuarios(),
                    'gerenciarPrioridades' => $user->podeGerenciarPrioridades(),
                    'interagir'            => $user->podeInteragir(),
                ] : null,
            ],
            // O sino: estado inicial da lista. Depois disso quem mantém ao vivo
            // é o evento NotificacoesAtualizadas no canal privado do usuário.
            'notificacoes' => $user ? Notificador::paraUsuario($user) : null,

            // Mensagens de uma ação (ex.: "Nota movida.") — o layout mostra como
            // toast. Sem isto, o ->with('sucesso', ...) dos controllers se perde.
            'flash' => [
                'sucesso' => fn() => $request->session()->get('sucesso'),
                'erro'    => fn() => $request->session()->get('erro'),
            ],
        ];
    }
}
