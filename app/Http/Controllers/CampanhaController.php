<?php

namespace App\Http\Controllers;

use App\Models\CampanhaAtendimento;
use App\Models\CampanhaParcela;
use App\Models\CampanhaFornecedor;
use App\Models\CampanhaTexto;
use App\Models\Configuracao;
use App\Models\Fornecedor;
use App\Services\DocumentoWord;
use App\Services\PlanilhaExcel;
use App\Support\CartaCampanha;
use App\Support\PlanilhaDeCompras;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
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

    // ─── ATENDIDOS ────────────────────────────────────────────────────────────
    //
    // A lista do que já foi feito: quem recebeu a campanha e quanto do combinado
    // já entrou. Até aqui a aba só montava a carta e a entregava — o resto vivia
    // na cabeça do comprador.
    //
    // A lista é de CADA UM. O comprador acompanha os fornecedores que ele mesmo
    // atendeu; ninguém tromba no trabalho do outro e a lista fica curta.

    /**
     * A lista inteira, do mais novo para o mais antigo.
     *
     * De TODOS os compradores, e nao so de quem pediu: a tela filtra por nome ali
     * mesmo, e e isso que evita dois compradores baterem no mesmo fornecedor sem
     * saber. Quem MEXE em cada linha continua sendo o dono dela (ou o admin).
     */
    public function atendidos(): JsonResponse
    {
        return response()->json([
            'atendidos' => CampanhaAtendimento::with(['user:id,name', 'parcelas'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get()->map(fn(CampanhaAtendimento $a) => $a->paraTela()),
            // O filtro volta junto com a lista: sao a mesma tela, e pedir os dois
            // em requisicoes separadas faria a lista piscar sem filtro antes de
            // se corrigir.
            'filtroSalvo' => request()->user()->campanha_filtro_comprador,
        ]);
    }

    /**
     * Baixa a lista em Excel.
     *
     * Sem filtro de comprador de proposito: quem exporta quer o panorama, e
     * filtrar por nome no proprio Excel e um clique. Mandar so a fatia da tela
     * daria um arquivo que engana quem o recebe por e-mail.
     */
    public function exportarAtendidos(): HttpResponse
    {
        $linhas = CampanhaAtendimento::with(['user:id,name', 'parcelas'])
            ->orderBy('fornecedor')
            ->get()
            ->map(function (CampanhaAtendimento $a) {
                $pct = $a->percentualPago();

                return [
                    $a->user?->name ?? '—',
                    $a->fornecedor,
                    $a->faturamento === null ? '' : (float) $a->faturamento,
                    (float) $a->investimento,
                    (float) $a->pago,
                    // Sem meta nao ha percentual: '' em vez de 0, senao a planilha
                    // afirmaria que falta tudo de uma meta que nao existe.
                    $pct === null ? '' : round($pct, 2),
                    $a->falta(),
                    // Quantas entradas e quando foi a ultima: e o que diz se o
                    // fornecedor esta pagando ou parou no meio, que o total
                    // sozinho nao conta.
                    $a->parcelas->count(),
                    optional($a->parcelas->last()?->data)->format('d/m/Y') ?? '',
                    optional($a->created_at)->format('d/m/Y'),
                ];
            })
            ->all();

        $planilha = PlanilhaExcel::montar(
            ['Comprador', 'Fornecedor', 'Faturamento', 'Meta', 'Pago', '% pago', 'Falta',
             'Parcelas', 'Ultima parcela', 'Incluido em'],
            $linhas,
            'Atendidos',
        );

        $nome = 'Campanha - fornecedores atendidos ' . now()->format('d-m-Y') . '.xlsx';

        return response($planilha, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length'      => (string) strlen($planilha),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $nome,
                $this->semAcento($nome),
            ),
        ]);
    }

    /**
     * Inclui um fornecedor na lista.
     *
     * O faturamento e a meta são COPIADOS para a linha, e não lidos depois da
     * campanha_fornecedores: a planilha troca aquela tabela a cada envio, e sem a
     * cópia a meta combinada hoje seria recalculada amanhã por outro faturamento.
     * O que foi combinado é um fato daquele dia.
     */
    public function incluirAtendido(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'fornecedor'   => ['required', 'string', 'max:255'],
            // Os dois chegam prontos da tela, que já mostra e deixa ajustar. O
            // servidor calcula a meta só quando ela não vem.
            'faturamento'  => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'investimento' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'pago'         => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $nome  = trim(preg_replace('/\s+/', ' ', $dados['fornecedor']) ?? $dados['fornecedor']);
        $chave = CampanhaFornecedor::chaveDe($nome);

        if ($nome === '') {
            return response()->json(['erro' => 'Informe o fornecedor.'], 422);
        }

        /*
         * Já está na lista de ALGUÉM.
         *
         * A checagem é global, e não mais só da própria lista: duas linhas do
         * mesmo fornecedor fariam a meta e o "quanto falta" serem lidos em dobro
         * no total — e, pior, dois compradores estariam trabalhando o mesmo
         * parceiro sem saber.
         *
         * A recusa diz DE QUEM é: "já está na lista" mandaria a pessoa procurar
         * numa lista de dezenas para descobrir com quem falar.
         */
        $dono = CampanhaAtendimento::with('user:id,name')->where('chave', $chave)->first();

        if ($dono) {
            $ehMeu = $dono->user_id === $request->user()->id;

            return response()->json([
                'erro' => $ehMeu
                    ? 'Este fornecedor já está na sua lista.'
                    : sprintf('%s já incluiu este fornecedor na lista dele.', $dono->user?->name ?? 'Outro comprador'),
            ], 422);
        }

        $faturamento = $dados['faturamento'] ?? null;

        // Sem meta informada, aplica o percentual sugerido sobre o faturamento —
        // é o "2% automático" que a tela promete.
        $investimento = $dados['investimento']
            ?? ($faturamento === null ? 0 : $faturamento * (CartaCampanha::PERCENTUAL_SUGERIDO / 100));

        $atendido = CampanhaAtendimento::create([
            'user_id'      => $request->user()->id,
            'fornecedor'   => $nome,
            'chave'        => $chave,
            'faturamento'  => $faturamento,
            'investimento' => $investimento,
            'pago'         => $dados['pago'] ?? 0,
        ]);

        return response()->json(['atendido' => $this->comParcelas($atendido)], 201);
    }

    /** Atualiza o que já foi pago (ou ajusta a meta combinada). */
    public function atualizarAtendido(Request $request, CampanhaAtendimento $atendido): JsonResponse
    {
        $this->soDonoOuAdmin($request, $atendido);

        /*
         * `pago` NAO entra aqui.
         *
         * Ele e a soma das parcelas desde que elas passaram a existir. Deixar os
         * dois editaveis criaria a pergunta "qual esta certo?" toda vez que
         * divergissem — e eles divergiriam no primeiro dia.
         */
        $dados = $request->validate([
            'investimento' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $atendido->update($dados);

        return response()->json(['atendido' => $this->comParcelas($atendido)]);
    }

    public function removerAtendido(Request $request, CampanhaAtendimento $atendido): JsonResponse
    {
        $this->soDonoOuAdmin($request, $atendido);

        $atendido->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Ver é de todos; MEXER é do dono — e do admin.
     *
     * O admin entra porque a lista sobrevive a férias e a desligamento: sem ele,
     * um acordo ficaria congelado esperando alguém que não volta. 404 e não 403
     * de propósito: para quem não pode, aquela linha não existe.
     */
    private function soDonoOuAdmin(Request $request, CampanhaAtendimento $atendido): void
    {
        $eu = $request->user();

        abort_if($atendido->user_id !== $eu->id && ! $eu->isAdmin(), 404);
    }

    // ─── PARCELAS ─────────────────────────────────────────────────────────────

    /**
     * Lança uma entrada de dinheiro do fornecedor.
     *
     * Fornecedor grande paga parcelado, e um total sozinho não dizia se foi uma
     * vez ou quatro, nem quando — que é o que se precisa saber para cobrar o
     * resto.
     */
    public function incluirParcela(Request $request, CampanhaAtendimento $atendido): JsonResponse
    {
        $this->soDonoOuAdmin($request, $atendido);

        $dados = $request->validate([
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            // `date_format` e não `date`: queremos exatamente o dia, e `date`
            // aceitaria texto solto que o Carbon adivinha.
            'data'  => ['required', 'date_format:Y-m-d'],
        ], [
            'valor.gt' => 'A parcela precisa ser maior que zero.',
        ]);

        $atendido->parcelas()->create($dados);
        $atendido->recalcularPago();

        return response()->json(['atendido' => $this->comParcelas($atendido)], 201);
    }

    public function removerParcela(Request $request, CampanhaAtendimento $atendido, CampanhaParcela $parcela): JsonResponse
    {
        $this->soDonoOuAdmin($request, $atendido);

        // Parcela de outro atendimento: endereço que não existe para este.
        abort_if($parcela->campanha_atendimento_id !== $atendido->id, 404);

        $parcela->delete();
        $atendido->recalcularPago();

        return response()->json(['atendido' => $this->comParcelas($atendido)]);
    }

    /**
     * Guarda o filtro por comprador da tela de atendidos.
     *
     * Na conta e não no navegador: quem acompanha os próprios fornecedores
     * reabre a aba no mesmo lugar, inclusive de outra máquina.
     */
    public function salvarFiltroAtendidos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            // null = "Todos".
            'comprador' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $request->user()->update(['campanha_filtro_comprador' => $dados['comprador'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** A linha recarregada com as parcelas — o formato que a tela espera. */
    private function comParcelas(CampanhaAtendimento $atendido): array
    {
        return $atendido->fresh(['user:id,name', 'parcelas'])->paraTela();
    }
}
