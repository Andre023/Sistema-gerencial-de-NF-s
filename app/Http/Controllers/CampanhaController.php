<?php

namespace App\Http\Controllers;

use App\Models\CampanhaFornecedor;
use App\Models\CampanhaTexto;
use App\Models\Configuracao;
use App\Models\Fornecedor;
use App\Services\DocumentoWord;
use App\Support\CartaCampanha;
use App\Support\PlanilhaDeCompras;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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

            'fornecedores' => $this->fornecedoresParaSugerir(),
            'base'         => $this->baseDeFaturamento(),

            'limiteDeCaracteres'  => CartaCampanha::LIMITE_DE_CARACTERES,
            'percentualSugerido'  => CartaCampanha::PERCENTUAL_SUGERIDO,
        ]);
    }

    /**
     * A lista que alimenta o campo de fornecedor.
     *
     * Com a planilha de compras enviada, sugere OS FORNECEDORES DELA — são os
     * que têm faturamento para preencher sozinho. Sem planilha, cai no cadastro
     * de fornecedores das notas, que ao menos evita erro de digitação no nome.
     *
     * O campo continua de texto livre nos dois casos: o parceiro da campanha
     * pode não estar em nenhuma das duas listas.
     *
     * @return list<array{nome: string, faturamento: float|null}>
     */
    private function fornecedoresParaSugerir(): array
    {
        $daPlanilha = CampanhaFornecedor::orderBy('nome')->get(['nome', 'faturamento']);

        if ($daPlanilha->isNotEmpty()) {
            return $daPlanilha
                ->map(fn(CampanhaFornecedor $f) => [
                    'nome'        => $f->nome,
                    'faturamento' => (float) $f->faturamento,
                ])
                ->all();
        }

        return Fornecedor::orderBy('nome')
            ->pluck('nome')
            ->map(fn(string $nome) => ['nome' => $nome, 'faturamento' => null])
            ->all();
    }

    /** De onde veio a base hoje — ou null, se ninguém enviou planilha ainda. */
    private function baseDeFaturamento(): ?array
    {
        $bruto = Configuracao::obter(Configuracao::CAMPANHA_BASE);

        if ($bruto === null) {
            return null;
        }

        $base = json_decode($bruto, true);

        // A tabela é a fonte da verdade: se alguém apagou a base por fora, o
        // rótulo não pode continuar prometendo fornecedor que não existe mais.
        if (! is_array($base) || CampanhaFornecedor::query()->doesntExist()) {
            return null;
        }

        return $base;
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

    /**
     * Recebe o ranking de compras do ERP e troca a base de faturamento inteira.
     *
     * É substituição, não mistura: a planilha é uma fotografia dos últimos 12
     * meses, e juntar a foto nova com a velha deixaria na tela fornecedor que
     * saiu do ranking, com valor de um período que já passou.
     *
     * A troca inteira roda dentro de uma transação — planilha recusada no meio
     * do caminho não pode deixar a base pela metade, com uns fornecedores da
     * foto nova e outros da antiga.
     */
    public function importarPlanilha(Request $request): RedirectResponse
    {
        $request->validate([
            // 10 MB: a base real tem 217 KB, mas planilha do ERP costuma vir
            // com aba extra e formatação que incham o arquivo.
            'planilha' => ['required', 'file', 'max:10240'],
        ]);

        $arquivo = $request->file('planilha');

        if (strtolower((string) $arquivo->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages([
                'planilha' => 'Envie em .xlsx — no Excel: Arquivo → Salvar como → Pasta de Trabalho do Excel.',
            ]);
        }

        try {
            $linhas = PlanilhaDeCompras::ler((string) $arquivo->getRealPath());
        } catch (RuntimeException $erro) {
            // O leitor explica o que faltou ("não achei a coluna Fornecedor");
            // vira erro de campo, embaixo do seletor de arquivo.
            throw ValidationException::withMessages(['planilha' => $erro->getMessage()]);
        }

        DB::transaction(function () use ($linhas) {
            CampanhaFornecedor::query()->delete();

            // Em lotes: 1.075 linhas num INSERT só estoura o limite de
            // marcadores do MySQL e a memória da VM sem precisar.
            foreach (array_chunk($linhas, 500) as $lote) {
                CampanhaFornecedor::insert(array_map(fn(array $linha) => [
                    'nome'        => mb_substr($linha['nome'], 0, 200),
                    'chave'       => mb_substr(CampanhaFornecedor::chaveDe($linha['nome']), 0, 200),
                    'faturamento' => $linha['faturamento'],
                ], $lote));
            }
        });

        Configuracao::definir(Configuracao::CAMPANHA_BASE, json_encode([
            'arquivo'     => mb_substr($arquivo->getClientOriginalName(), 0, 120),
            'linhas'      => count($linhas),
            'enviada_em'  => now()->toIso8601String(),
            'enviado_por' => $request->user()->name,
        ], JSON_UNESCAPED_UNICODE));

        return redirect()->route('campanha.index')->with(
            'sucesso',
            number_format(count($linhas), 0, ',', '.') . ' fornecedores importados da planilha.',
        );
    }

    /** Apaga a base — para quando a planilha subiu errada. */
    public function removerPlanilha(): RedirectResponse
    {
        CampanhaFornecedor::query()->delete();
        Configuracao::definir(Configuracao::CAMPANHA_BASE, null);

        return redirect()->route('campanha.index')->with('sucesso', 'Base de faturamento removida.');
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
