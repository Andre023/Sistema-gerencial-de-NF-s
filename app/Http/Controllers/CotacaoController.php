<?php

namespace App\Http\Controllers;

use App\Events\CotacaoAlterada;
use App\Models\Cotacao;
use App\Models\CotacaoFolha;
use App\Services\ExportacaoDaCotacao;
use App\Services\MontagemDaCotacao;
use App\Support\LeitorDePlanilha;
use App\Support\OperacoesDaFolha;
use App\Support\PlanilhaDaCotacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as RespostaHttp;

/**
 * A aba Cotação — a cotação CEASA de Mercearia e Bebidas, do estoque baixo ao
 * pedido.
 *
 * Substitui o HTML avulso que guardava tudo no navegador. O caminho é o mesmo:
 *
 *   1. as listas de estoque baixo das lojas entram (e podem ser corrigidas);
 *   2. a prévia junta os produtos sem repetir e vira o .xlsx dos fornecedores;
 *   3. as respostas voltam com a coluna Valor preenchida;
 *   4. a planilha de compra mostra o 1º e o 2º menor preço de cada produto,
 *      com Fator, Quantidade e Preço fornecedor para o comprador preencher.
 *
 * Tudo é uma folha editável, e a edição vale para todos os compradores ao
 * mesmo tempo (ver OperacoesDaFolha e o evento CotacaoAlterada).
 */
class CotacaoController extends Controller
{
    /** Um envio de planilhas: até 8 arquivos de 5 MB. */
    private const ARQUIVOS = ['required', 'array', 'min:1', 'max:8'];
    private const ARQUIVO = ['file', 'max:5120'];

    public function index(): Response
    {
        $cotacoes = Cotacao::query()
            ->with('criadaPor:id,name')
            ->withCount([
                'folhas as listas'    => fn($q) => $q->where('tipo', 'lista'),
                'folhas as respostas' => fn($q) => $q->where('tipo', 'resposta'),
                'folhas as compras'   => fn($q) => $q->where('tipo', 'compra'),
            ])
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(fn(Cotacao $c) => [
                'id'           => $c->id,
                'titulo'       => $c->titulo,
                'criada_por'   => $c->criadaPor?->name,
                'criada_em'    => $c->created_at?->toIso8601String(),
                'alterada_em'  => $c->updated_at?->toIso8601String(),
                'listas'       => $c->listas,
                'respostas'    => $c->respostas,
                'compras'      => $c->compras,
            ]);

        return Inertia::render('Cotacao/Index', ['cotacoes' => $cotacoes]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate(['titulo' => ['nullable', 'string', 'max:120']]);

        $cotacao = Cotacao::create([
            'titulo'     => trim($dados['titulo'] ?? '') ?: 'Cotação ' . now()->format('d/m/Y'),
            'criada_por' => $request->user()->id,
        ]);

        return redirect()->route('cotacao.mostrar', $cotacao);
    }

    public function mostrar(Cotacao $cotacao): Response
    {
        $cotacao->load('criadaPor:id,name');

        return Inertia::render('Cotacao/Mostrar', [
            'cotacao' => [
                'id'         => $cotacao->id,
                'titulo'     => $cotacao->titulo,
                'criada_por' => $cotacao->criadaPor?->name,
                'criada_em'  => $cotacao->created_at?->toIso8601String(),
            ],
            'folhas'  => $cotacao->folhas()->get()->map->paraTela(),
            'lojas'   => collect(Cotacao::LOJAS)->map(fn($l, $id) => ['id' => $id, 'nome' => $l['nome']])->values(),
            'setores' => collect(Cotacao::SETORES)->map(fn($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
        ]);
    }

    public function renomear(Request $request, Cotacao $cotacao): RedirectResponse
    {
        $dados = $request->validate(['titulo' => ['required', 'string', 'max:120']]);
        $cotacao->update(['titulo' => trim($dados['titulo'])]);

        return back();
    }

    public function excluir(Cotacao $cotacao): RedirectResponse
    {
        $cotacao->delete();

        return redirect()->route('cotacao.index');
    }

    // ─── Entrada de planilhas ──────────────────────────────────────────────────

    /** As listas de estoque baixo. Cada arquivo vira uma folha. */
    public function importarListas(Request $request, Cotacao $cotacao): JsonResponse
    {
        $request->validate(['arquivos' => self::ARQUIVOS, 'arquivos.*' => self::ARQUIVO]);

        return $this->importar($request, $cotacao, function (UploadedFile $arquivo) use ($cotacao) {
            $nome = $arquivo->getClientOriginalName();
            $lista = PlanilhaDaCotacao::lerLista(LeitorDePlanilha::ler($arquivo->getRealPath(), $nome), $nome);

            return $cotacao->folhas()->create([
                'tipo'    => 'lista',
                'nome'    => $lista['titulo'] ?: PlanilhaDaCotacao::semExtensao($nome),
                'arquivo' => mb_substr($nome, 0, 255),
                'meta'    => ['loja' => $lista['loja'], 'setor' => $lista['setor']],
                'colunas' => MontagemDaCotacao::colunas($cotacao, 'lista'),
                'linhas'  => $lista['linhas'],
                'ordem'   => MontagemDaCotacao::proximaOrdem($cotacao, 'lista'),
            ]);
        });
    }

    /** As cotações devolvidas pelos fornecedores. */
    public function importarRespostas(Request $request, Cotacao $cotacao): JsonResponse
    {
        $request->validate(['arquivos' => self::ARQUIVOS, 'arquivos.*' => self::ARQUIVO]);

        return $this->importar($request, $cotacao, function (UploadedFile $arquivo) use ($cotacao) {
            $nome = $arquivo->getClientOriginalName();
            $resposta = PlanilhaDaCotacao::lerResposta(LeitorDePlanilha::ler($arquivo->getRealPath(), $nome), $nome);

            return $cotacao->folhas()->create([
                'tipo'    => 'resposta',
                'nome'    => $resposta['fornecedor'],
                'arquivo' => mb_substr($nome, 0, 255),
                'meta'    => ['fornecedor' => $resposta['fornecedor']],
                'colunas' => MontagemDaCotacao::colunas($cotacao, 'resposta'),
                'linhas'  => $resposta['linhas'],
                'ordem'   => MontagemDaCotacao::proximaOrdem($cotacao, 'resposta'),
            ]);
        });
    }

    /**
     * Uma folha começada do zero: a lista de uma loja que não veio do ERP, ou
     * a resposta de quem passou os preços por telefone.
     */
    public function folhaEmBranco(Request $request, Cotacao $cotacao): JsonResponse
    {
        $dados = $request->validate([
            'tipo'       => ['required', Rule::in(['lista', 'resposta'])],
            'loja'       => ['nullable', Rule::in(array_keys(Cotacao::LOJAS))],
            'setor'      => ['nullable', Rule::in(array_keys(Cotacao::SETORES))],
            'fornecedor' => ['required_if:tipo,resposta', 'nullable', 'string', 'max:120'],
        ]);

        if ($dados['tipo'] === 'resposta') {
            $folha = MontagemDaCotacao::respostaEmBranco($cotacao, trim($dados['fornecedor']));
        } else {
            $loja = $dados['loja'] ?? '';
            $setor = $dados['setor'] ?? 'merc';

            $folha = $cotacao->folhas()->create([
                'tipo'    => 'lista',
                'nome'    => trim((Cotacao::SETORES[$setor] ?? '') . ' ' . (Cotacao::LOJAS[$loja]['nome'] ?? '')) ?: 'Lista nova',
                'meta'    => ['loja' => $loja, 'setor' => $setor],
                'colunas' => MontagemDaCotacao::colunas($cotacao, 'lista'),
                'linhas'  => array_map(fn() => PlanilhaDaCotacao::linha([]), range(1, 20)),
                'ordem'   => MontagemDaCotacao::proximaOrdem($cotacao, 'lista'),
            ]);
        }

        $this->avisar($request, $folha);

        return response()->json(['folha' => $folha->paraTela()]);
    }

    // ─── Folhas geradas ────────────────────────────────────────────────────────

    public function gerarPrevia(Request $request, Cotacao $cotacao): JsonResponse
    {
        if (! $cotacao->folhas()->where('tipo', 'lista')->exists()) {
            return response()->json(['message' => 'Coloque pelo menos uma lista de compra antes.'], 422);
        }

        $folha = DB::transaction(fn() => MontagemDaCotacao::previa($cotacao));
        $this->avisar($request, $folha);

        return response()->json(['folha' => $folha->paraTela()]);
    }

    public function gerarCompra(Request $request, Cotacao $cotacao): JsonResponse
    {
        $listas = $cotacao->folhas()->where('tipo', 'lista')->get();

        if ($listas->isEmpty()) {
            return response()->json(['message' => 'Coloque pelo menos uma lista de compra antes.'], 422);
        }

        if ($listas->contains(fn($l) => ! isset(Cotacao::LOJAS[$l->meta['loja'] ?? '']))) {
            return response()->json(['message' => 'Escolha de qual loja é cada lista de compra antes de gerar.'], 422);
        }

        $folhas = DB::transaction(fn() => MontagemDaCotacao::compra($cotacao));
        $folhas->each(fn($f) => $this->avisar($request, $f));

        return response()->json(['folhas' => $folhas->map->paraTela()->values()]);
    }

    // ─── Edição ────────────────────────────────────────────────────────────────

    public function folha(Cotacao $cotacao, CotacaoFolha $folha): JsonResponse
    {
        return response()->json(['folha' => $folha->paraTela()]);
    }

    /**
     * Aplica as alterações que a tela fez. A folha é travada enquanto isso: duas
     * levas chegando juntas entram uma depois da outra, cada uma com a sua
     * versão — e é pela versão que as outras telas sabem a ordem.
     */
    public function alterar(Request $request, Cotacao $cotacao, CotacaoFolha $folha): JsonResponse
    {
        $dados = $request->validate([
            'ops'     => ['required', 'array', 'min:1', 'max:500'],
            'cliente' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $versao = DB::transaction(function () use ($folha, $dados) {
                $atual = CotacaoFolha::query()->lockForUpdate()->findOrFail($folha->id);

                $nova = OperacoesDaFolha::aplicar([
                    'nome'    => $atual->nome,
                    'meta'    => $atual->meta ?? [],
                    'colunas' => $atual->colunas ?? [],
                    'linhas'  => $atual->linhas ?? [],
                ], $dados['ops']);

                $atual->fill($nova);
                $atual->versao++;
                $atual->save();
                $atual->cotacao->touch();

                if (collect($dados['ops'])->contains(fn($op) => in_array($op['t'] ?? null, ['col', 'delcol'], true)
                    || (($op['t'] ?? null) === 'est' && ($op['alvo'] ?? null) === 'col'))) {
                    MontagemDaCotacao::guardarModelo($atual);
                }

                return $atual->versao;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        broadcast(new CotacaoAlterada($cotacao->id, $folha->id, $versao, (string) ($dados['cliente'] ?? ''), $dados['ops']));

        return response()->json(['versao' => $versao]);
    }

    public function removerFolha(Request $request, Cotacao $cotacao, CotacaoFolha $folha): JsonResponse
    {
        $folha->delete();
        $cotacao->touch();

        broadcast(new CotacaoAlterada($cotacao->id, $folha->id, $folha->versao + 1, $this->cliente($request), removida: true));

        return response()->json(['ok' => true]);
    }

    // ─── Saída ─────────────────────────────────────────────────────────────────

    /** ?setor=merc|beb baixa só aquele setor; sem ele, os dois. */
    public function exportarLista(Request $request, Cotacao $cotacao): RespostaHttp
    {
        $setor = $request->validate(['setor' => ['nullable', Rule::in(array_keys(Cotacao::SETORES))]])['setor'] ?? null;
        $sufixo = $setor !== null ? ' ' . Cotacao::SETORES[$setor] : '';

        return $this->baixar(
            ExportacaoDaCotacao::listaParaFornecedores($cotacao, $setor),
            'Cotação CEASA' . $sufixo . ' ' . now()->format('d-m') . '.xlsx',
        );
    }

    public function exportarCompra(Cotacao $cotacao): RespostaHttp
    {
        return $this->baixar(ExportacaoDaCotacao::compra($cotacao), 'Compra CEASA ' . now()->format('d-m') . '.xlsx');
    }

    public function exportarFolha(Cotacao $cotacao, CotacaoFolha $folha): RespostaHttp
    {
        return $this->baixar(ExportacaoDaCotacao::folha($folha), PlanilhaDaCotacao::semExtensao($folha->nome) . '.xlsx');
    }

    // ─── Apoio ─────────────────────────────────────────────────────────────────

    /**
     * Lê cada arquivo do envio. Um arquivo ruim não derruba os outros: volta na
     * lista de erros com o nome dele, e os bons entram.
     */
    private function importar(Request $request, Cotacao $cotacao, callable $ler): JsonResponse
    {
        $folhas = [];
        $erros = [];

        foreach ($request->file('arquivos') as $arquivo) {
            // Pela extensão, como na Campanha: o detector de tipo do PHP vê o
            // .xlsx como um .zip qualquer.
            if (! in_array(strtolower((string) $arquivo->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
                $erros[] = $arquivo->getClientOriginalName() . ': envie em .xlsx — no Excel: Arquivo → Salvar como → Pasta de Trabalho do Excel.';
                continue;
            }

            try {
                $folha = $ler($arquivo);
                $folhas[] = $folha->paraTela();
                $this->avisar($request, $folha);
            } catch (RuntimeException $e) {
                $erros[] = $arquivo->getClientOriginalName() . ': ' . $e->getMessage();
            }
        }

        $cotacao->touch();

        return response()->json(['folhas' => $folhas, 'erros' => $erros], $folhas === [] ? 422 : 200);
    }

    /** Folha nova ou gerada de novo: os outros buscam ela inteira. */
    private function avisar(Request $request, CotacaoFolha $folha): void
    {
        broadcast(new CotacaoAlterada($folha->cotacao_id, $folha->id, $folha->versao, $this->cliente($request)));
    }

    private function cliente(Request $request): string
    {
        return mb_substr((string) $request->input('cliente', ''), 0, 40);
    }

    private function baixar(string $bytes, string $nome): RespostaHttp
    {
        return response($bytes, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $this->ascii($nome)) . '"; filename*=UTF-8\'\'' . rawurlencode($nome),
        ]);
    }

    private function ascii(string $nome): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: 'cotacao.xlsx';
    }
}
