<?php

namespace App\Http\Controllers;

use App\Models\CampanhaTexto;
use App\Models\Configuracao;
use App\Models\Fornecedor;
use App\Services\DocumentoWord;
use App\Support\CartaCampanha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A aba Campanha — a carta de aniversário que o comprador manda ao fornecedor.
 *
 * A tela é um editor: o esqueleto do texto de um lado, os três dados do
 * fornecedor do outro, e o Word pronto no fim. O esqueleto é do comprador (cada
 * um salva o seu); o padrão da loja é do admin, em Configurações.
 *
 * Quem entra aqui é compras (e o admin), e só enquanto a campanha estiver
 * ligada — a trava é o Gate 'usar-campanha', nas rotas.
 */
class CampanhaController extends Controller
{
    public function index(Request $request): Response
    {
        $perfil = CampanhaTexto::where('user_id', $request->user()->id)->first();
        $padrao = Configuracao::campanhaTextoPadrao();

        return Inertia::render('Campanha/Index', [
            'texto'     => $perfil?->texto ?? $padrao,
            'padrao'    => $padrao,
            'temPerfil' => $perfil !== null,

            // Só os nomes: o campo é de texto livre (o parceiro pode nem estar
            // cadastrado), e a lista serve para sugerir e evitar erro de
            // digitação no nome que vai impresso na carta.
            'fornecedores' => Fornecedor::orderBy('nome')->pluck('nome'),

            'limiteDeCaracteres' => CartaCampanha::LIMITE_DE_CARACTERES,
        ]);
    }

    /** Salva o esqueleto desta pessoa — um por conta. */
    public function salvarTexto(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'texto' => ['required', 'string', 'max:' . CartaCampanha::LIMITE_DE_CARACTERES],
        ]);

        CampanhaTexto::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['texto'   => $dados['texto']],
        );

        // Volta para a própria tela em vez de back(): logo depois de um F5 o
        // back() cai na página anterior da sessão, e quem salvou o texto
        // perderia os dados do fornecedor já digitados.
        return redirect()->route('campanha.index')->with('sucesso', 'Seu texto foi salvo.');
    }

    /**
     * Volta ao texto padrão: apaga o perfil desta pessoa em vez de sobrescrevê-lo
     * com o padrão de hoje. A diferença aparece no ano que vem — quem restaurou
     * passa a abrir a tela com o padrão NOVO, não com uma cópia do antigo.
     */
    public function restaurarTexto(Request $request): RedirectResponse
    {
        CampanhaTexto::where('user_id', $request->user()->id)->delete();

        return redirect()->route('campanha.index')->with('sucesso', 'Texto padrão restaurado.');
    }

    /** Gera o .docx da carta e devolve para download. Nada é gravado. */
    public function baixar(Request $request): HttpResponse
    {
        $dados = $request->validate([
            'texto'        => ['required', 'string', 'max:' . CartaCampanha::LIMITE_DE_CARACTERES],
            'fornecedor'   => ['required', 'string', 'max:200'],
            'faturamento'  => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'investimento' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $fornecedor = trim($dados['fornecedor']);

        $paragrafos = CartaCampanha::montar(
            $dados['texto'],
            $fornecedor,
            (float) $dados['faturamento'],
            (float) $dados['investimento'],
        );

        $nome = $this->nomeDoArquivo($fornecedor);

        $documento = DocumentoWord::carta($paragrafos, 'Campanha de aniversário — ' . $fornecedor);

        return response($documento, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Length'      => (string) strlen($documento),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $nome,
                $this->semAcento($nome), // nome alternativo, para navegador antigo
            ),
        ]);
    }

    /**
     * "Aniversário - VIVALOG DISTRIBUICAO.docx".
     *
     * Tira o que o Windows não aceita em nome de arquivo (\ / : * ? " < > |) —
     * nome de fornecedor vem com barra e aspas mais vezes do que parece.
     */
    private function nomeDoArquivo(string $fornecedor): string
    {
        $limpo = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $fornecedor);
        $limpo = trim(preg_replace('/\s+/u', ' ', (string) $limpo));

        if ($limpo === '') {
            $limpo = 'fornecedor';
        }

        return 'Aniversário - ' . mb_substr($limpo, 0, 80) . '.docx';
    }

    private function semAcento(string $texto): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return preg_replace('/[^\x20-\x7E]/', '', $sem ?: 'campanha.docx') ?: 'campanha.docx';
    }
}
