<?php

namespace App\Http\Middleware;

use App\Services\Conversas;
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
                    'abrirCardCadastro' => $user->podeAbrirCardDeCadastro(),
                    'liberarNota'       => $user->podeLiberarNota(),
                    'editarNotas'       => $user->podeEditarNotas(),
                    'devolverNota'      => $user->podeDevolverNota(),
                    'gerenciarNotas'    => $user->podeGerenciarNotas(),
                    'excluirNotaLiberada' => $user->podeExcluirNotaLiberada(),
                    'verEstatisticas'   => $user->podeVerEstatisticas(),
                    'verDossie'         => $user->podeVerDossie(),
                    'gerenciarUsuarios' => $user->podeGerenciarUsuarios(),
                    'gerenciarPrioridades' => $user->podeGerenciarPrioridades(),
                    'interagir'            => $user->podeInteragir(),
                    'cancelarNota'         => $user->podeCancelarNota(),
                    'editarObservacao'     => $user->podeEditarObservacao(),
                    'editarCeasaLiberada'      => $user->podeEditarCeasaLiberada(),
                    'anexarNota'              => $user->podeAnexarNota(),
                    'usarDevolucoes'          => $user->podeUsarDevolucoes(),
                    'usarCampanha'            => $user->podeUsarCampanha(),
                    'gerenciarConfiguracoes'  => $user->podeGerenciarConfiguracoes(),
                ] : null,
            ],
            // O sino: estado inicial da lista. Depois disso quem mantém ao vivo
            // é o evento NotificacoesAtualizadas no canal privado do usuário.
            'notificacoes' => $user ? Notificador::paraUsuario($user) : null,

            // Chat: quem está com mensagem por ler, da mais recente para a mais
            // antiga. É o que põe o rosto de quem falou no topo da barra
            // recolhida assim que a página abre — antes disso só havia um número
            // aceso, sem dizer de quem era.
            //
            // Traz SÓ quem tem pendência (quase sempre ninguém), e não as 26
            // contas: a lista inteira continua sendo buscada sob demanda, quando
            // alguém expande a barra.
            'conversasPendentes' => $user ? Conversas::pendentesDe($user) : [],

            // Mensagens de uma ação (ex.: "Nota movida.") — o layout mostra como
            // toast. Sem isto, o ->with('sucesso', ...) dos controllers se perde.
            'flash' => [
                'sucesso' => fn() => $request->session()->get('sucesso'),
                'erro'    => fn() => $request->session()->get('erro'),
            ],
        ];
    }
}
