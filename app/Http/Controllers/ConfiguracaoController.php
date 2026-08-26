<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Support\CartaCampanha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações — o painel do admin.
 *
 * A tela tem um seletor à esquerda e as seções à direita: Usuários (que saiu da
 * navbar para abrir espaço) e Campanha de aniversário. Cada seção é uma página
 * Inertia própria; o que dá a aparência de aba é o layout compartilhado
 * (resources/js/Pages/Configuracoes/Secoes.tsx).
 */
class ConfiguracaoController extends Controller
{
    public function campanha(): Response
    {
        return Inertia::render('Configuracoes/Campanha', [
            'ativa'          => Configuracao::campanhaAtiva(),
            'textoPadrao'    => Configuracao::campanhaTextoPadrao(),
            'textoDeFabrica' => CartaCampanha::TEXTO_DE_FABRICA,
            'limiteDeCaracteres' => CartaCampanha::LIMITE_DE_CARACTERES,
        ]);
    }

    /**
     * Liga/desliga a aba e guarda o texto padrão da loja.
     *
     * Desligada, a aba some do menu de todo mundo — inclusive do admin, que
     * continua chegando aqui por Configurações para religar.
     */
    public function atualizarCampanha(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'ativa'        => ['required', 'boolean'],
            'texto_padrao' => ['required', 'string', 'max:' . CartaCampanha::LIMITE_DE_CARACTERES],
        ]);

        Configuracao::definirCampanhaAtiva($dados['ativa']);
        Configuracao::definir(Configuracao::CAMPANHA_TEXTO_PADRAO, $dados['texto_padrao']);

        return redirect()->route('configuracoes.campanha')->with('sucesso', $dados['ativa']
            ? 'Campanha ativa — a aba está no menu de compras.'
            : 'Campanha desativada — a aba saiu do menu.');
    }
}
