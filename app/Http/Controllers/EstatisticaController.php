<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estatísticas sobre a fila de notas. As keys das props mantêm os nomes
 * genéricos (kpis, porMotivo...) para o frontend mudar pouco:
 * "atendida" = liberada; "motivo" = tipo de card (ou "sem divergência").
 *
 * REGRA DE OURO: toda consulta parte de Nota::ativas() — canceladas e excluídas
 * NÃO são trabalho pendente. Sem isso, a nota cancelada vira "pendente eterna"
 * e envenena a taxa de resolução (ver docs/NOTAS_CANCELADAS.md). O cancelamento
 * tem números próprios, no bloco "cancelamento".
 */
class EstatisticaController extends Controller
{
    use \App\Support\SqlData;

    public function index(Request $request): Response
    {
        /*
         * Por qual ponta contar o periodo. Ver base() para o porque de existir
         * como filtro em vez de uma escolha fixa.
         */
        $contarPor = $request->input('contarPor') === 'liberadas' ? 'liberadas' : 'lancadas';

        $periodo = (int) $request->input('periodo', 30);
        $periodo = max(1, min($periodo, 365));

        // Intervalo livre (de/até) — o dia único é o caso em que de = até.
        // Quando vem intervalo, ele manda no período.
        [$de, $ate, $periodo, $intervalo] = $this->janela($request, $periodo);

        // Filtros opcionais
        $lojas = array_values(array_filter(
            array_map('intval', (array) $request->input('loja', [])),
            fn($l) => in_array($l, Nota::LOJAS, true)
        ));
        $origem = in_array($request->input('origem'), Nota::ORIGENS, true) ? $request->input('origem') : null;
        // ceasa: null = tudo | 'so' = só CEASA | 'sem' = exclui CEASA
        $ceasa = in_array($request->input('ceasa'), ['so', 'sem'], true) ? $request->input('ceasa') : null;

        // Janela anterior de mesmo tamanho, para o comparativo dos KPIs.
        // Compara DIA a dia: o Carbon 3 devolve float e "01 00:00 → 01 23:59"
        // daria 0,99 dia (o +1 antes do cast virava 7,99 em vez de 7).
        $dias   = max(1, (int) $de->copy()->startOfDay()->diffInDays($ate->copy()->startOfDay()) + 1);
        $deAnt  = (clone $de)->subDays($dias);
        $ateAnt = (clone $de)->subSecond();

        // A janela e os filtros JÁ identificam o resultado — o que muda de um
        // minuto para o outro é só a nota nova, e disso cuida o TTL de 5 min.
        // Enquanto o minuto entrava aqui, cada acesso gerava uma chave inédita:
        // o cache gravava e nunca era lido, e a página pesada rodava inteira
        // toda vez (era exatamente o que o cacheado() abaixo queria evitar).
        $chave = 'stats:' . md5(json_encode([
            $de->toDateTimeString(), $ate->toDateTimeString(),
            $lojas, $origem, $ceasa,
            // O recorte entra na chave: sem ele, trocar de "lancadas" para
            // "liberadas" devolveria o resultado guardado da outra contagem —
            // o filtro pareceria nao funcionar.
            $contarPor,
        ]));

        $dados = $this->cacheado($chave, function () use ($de, $ate, $deAnt, $ateAnt, $lojas, $origem, $ceasa, $contarPor) {
            return $this->calcular($de, $ate, $deAnt, $ateAnt, $lojas, $origem, $ceasa, $contarPor);
        });

        return Inertia::render('Estatisticas/Index', [
            'periodo' => $periodo,
            'contarPor' => $contarPor,
            'intervalo' => [
                'de'      => $de->toDateString(),
                'ate'     => $ate->toDateString(),
                'dias'    => $dias,
                // true = o usuário escolheu datas (não é o "últimos N dias")
                'livre'   => $intervalo,
                'umDiaSo' => $de->toDateString() === $ate->toDateString(),
            ],
            'filtros' => ['loja' => $lojas, 'origem' => $origem, 'ceasa' => $ceasa],
            'opcoes'  => ['lojas' => Nota::LOJAS, 'origens' => Nota::ORIGENS],
            ...$dados,
        ]);
    }

    /**
     * Janela de análise: "últimos N dias" (padrão) ou um intervalo escolhido.
     *
     * Um dia único é só o caso de = até — por isso não existe um modo "dia":
     * o mesmo seletor cobre "ontem", "semana passada" e "julho fechado".
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon,2:int,3:bool}
     */
    private function janela(Request $request, int $periodo): array
    {
        $de  = $request->input('de');
        $ate = $request->input('ate');

        $valida = fn($d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

        if ($valida($de) || $valida($ate)) {
            // Um lado só preenchido vira dia único
            $inicio = \Carbon\Carbon::parse($valida($de) ? $de : $ate)->startOfDay();
            $fim    = \Carbon\Carbon::parse($valida($ate) ? $ate : $de)->endOfDay();

            if ($inicio->gt($fim)) {          // invertido: troca em vez de dar erro
                [$inicio, $fim] = [$fim->copy()->startOfDay(), $inicio->copy()->endOfDay()];
            }
            // Teto de 2 anos para não varrer a tabela inteira sem querer
            if ($inicio->diffInDays($fim) > 730) {
                $inicio = $fim->copy()->subDays(730)->startOfDay();
            }

            $dias = (int) $inicio->copy()->startOfDay()->diffInDays($fim->copy()->startOfDay()) + 1;

            return [$inicio, $fim, $dias, true];
        }

        return [now()->subDays($periodo - 1)->startOfDay(), now()->endOfDay(), $periodo, false];
    }

    // ─── Cálculo ──────────────────────────────────────────────────────────────

    private function calcular($de, $ate, $deAnt, $ateAnt, array $lojas, ?string $origem, ?string $ceasa, string $contarPor): array
    {
        $base = $this->base($de, $ate, $lojas, $origem, $ceasa, $contarPor);

        // A mesma coluna que recortou o periodo alimenta as series por data.
        $colunaPeriodo = $contarPor === 'liberadas' ? 'liberada_em' : 'created_at';

        // ── KPIs do período e do período anterior (para o comparativo) ────────
        $kpis    = $this->kpis(
            $this->base($de, $ate, $lojas, $origem, $ceasa, $contarPor),
            $this->baseLancadas($de, $ate, $lojas, $origem, $ceasa),
        );
        $kpisAnt = $this->kpis(
            $this->base($deAnt, $ateAnt, $lojas, $origem, $ceasa, $contarPor),
            $this->baseLancadas($deAnt, $ateAnt, $lojas, $origem, $ceasa),
        );

        $kpis['variacao'] = [
            'total'         => $this->variacao($kpis['total'], $kpisAnt['total']),
            'taxaResolucao' => $this->variacao($kpis['taxaResolucao'], $kpisAnt['taxaResolucao']),
            'tempoMedio'    => $this->variacao($kpis['tempoMedioHoras'], $kpisAnt['tempoMedioHoras']),
        ];

        // ── Evolução diária ───────────────────────────────────────────────────
        $evolucaoDiaria = (clone $base)
            // Agrupa pela MESMA coluna que recortou o periodo: misturar as duas
            // poria dois eixos de tempo no mesmo grafico.
            ->selectRaw("DATE({$colunaPeriodo}) as dia, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas,
                          SUM(CASE WHEN liberada_em IS NULL THEN 1 ELSE 0 END) as pendentes")
            ->groupBy('dia')->orderBy('dia')->get()
            ->map(fn($r) => [
                'dia' => $r->dia, 'total' => (int) $r->total,
                'atendidas' => (int) $r->atendidas, 'pendentes' => (int) $r->pendentes,
            ]);

        // ── Por tipo de divergência ───────────────────────────────────────────
        $porMotivo = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->selectRaw('cards.tipo as motivo, COUNT(*) as total,
                          SUM(CASE WHEN cards.status = "resolvido" THEN 1 ELSE 0 END) as atendidas')
            ->groupBy('cards.tipo')->orderByDesc('total')->get()
            ->map(fn($r) => [
                'motivo' => Card::rotulo($r->motivo),
                'total' => (int) $r->total, 'atendidas' => (int) $r->atendidas,
            ]);

        $semDivergencia = (clone $base)->whereNotNull('liberada_em')->whereDoesntHave('cards')->count();
        if ($semDivergencia > 0) {
            $porMotivo->push(['motivo' => 'Sem divergência', 'total' => $semDivergencia, 'atendidas' => $semDivergencia]);
        }

        // ── Por loja / dia da semana / hora ───────────────────────────────────
        $porLoja = (clone $base)
            ->selectRaw('loja, COUNT(*) as total, SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->groupBy('loja')->orderBy('loja')->get()
            ->map(fn($r) => ['loja' => (int) $r->loja, 'total' => (int) $r->total, 'atendidas' => (int) $r->atendidas]);

        $porDiaSemana = (clone $base)
            ->selectRaw("{$this->diaSemana($colunaPeriodo)} as dia_num, COUNT(*) as total")
            ->groupBy('dia_num')->orderBy('dia_num')->get()
            ->map(fn($r) => ['dia' => ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][$r->dia_num - 1], 'total' => (int) $r->total]);

        $porHora = (clone $base)
            ->selectRaw("{$this->hora($colunaPeriodo)} as hora, COUNT(*) as total")
            ->groupBy('hora')->orderBy('hora')->get()
            ->map(fn($r) => ['hora' => str_pad($r->hora, 2, '0', STR_PAD_LEFT) . 'h', 'total' => (int) $r->total]);

        // ── Fornecedores ──────────────────────────────────────────────────────
        $topFornecedores = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total, SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->with('fornecedor:id,nome')->groupBy('fornecedor_id')->orderByDesc('total')->limit(10)->get()
            ->map(fn($r) => ['fornecedor' => $r->fornecedor->nome ?? '—', 'total' => (int) $r->total, 'atendidas' => (int) $r->atendidas]);

        // UMA query para todos os tipos (antes era uma por tipo, num foreach)
        $fornecedoresPorMotivo = [];
        $linhas = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->join('fornecedores', 'fornecedores.id', '=', 'notas.fornecedor_id')
            ->selectRaw('cards.tipo, fornecedores.nome as fornecedor, COUNT(*) as total')
            ->groupBy('cards.tipo', 'fornecedores.nome')
            ->orderByDesc('total')->get();

        foreach ($linhas->groupBy('tipo') as $tipo => $grupo) {
            $fornecedoresPorMotivo[Card::rotulo($tipo)] = $grupo->take(8)
                ->map(fn($r) => ['fornecedor' => $r->fornecedor, 'total' => (int) $r->total])->values();
        }

        $reincidentes = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total, COUNT(DISTINCT DATE(created_at)) as dias_distintos')
            ->with('fornecedor:id,nome')->whereHas('cards')
            ->groupBy('fornecedor_id')->having('total', '>', 2)->orderByDesc('total')->limit(10)->get()
            ->map(fn($r) => ['fornecedor' => $r->fornecedor->nome ?? '—', 'total' => (int) $r->total, 'dias_distintos' => (int) $r->dias_distintos]);

        $rankingUsuarios = (clone $base)
            ->selectRaw('user_id, COUNT(*) as total, SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->with('user:id,name')->groupBy('user_id')->orderByDesc('total')->limit(10)->get()
            ->map(fn($r) => ['usuario' => $r->user->name ?? '—', 'total' => (int) $r->total, 'atendidas' => (int) $r->atendidas]);

        // ── Pendentes mais antigas (canceladas ficam de fora) ─────────────────
        $hojeStr = now()->toDateString();
        $pendentesMaisAntigas = Nota::ativas()->whereNull('liberada_em')
            ->with(['fornecedor:id,nome', 'user:id,name', 'cards'])
            ->when($lojas, fn($q) => $q->whereIn('loja', $lojas))
            ->when($origem, fn($q) => $q->where('origem', $origem))
            ->orderBy('created_at', 'asc')->limit(10)->get()
            ->map(fn($n) => [
                'id' => $n->id, 'numero_nota' => $n->numero_nota,
                'fornecedor' => $n->fornecedor->nome ?? '—',
                'motivo' => $n->cards->where('status', '!=', Card::STATUS_RESOLVIDO)
                    ->pluck('tipo')->map(fn($t) => Card::rotulo($t))->implode(', ') ?: 'Sem divergência',
                'loja' => $n->loja, 'dias_aberta' => $n->diasEmAberto($hojeStr),
                'nivel' => $n->nivelAlerta($hojeStr), 'created_at' => $n->created_at->format('d/m/Y H:i'),
            ]);

        return [
            'kpis' => $kpis,
            'evolucaoDiaria' => $evolucaoDiaria,
            'porMotivo' => $porMotivo,
            'porLoja' => $porLoja,
            'porDiaSemana' => $porDiaSemana,
            'porHora' => $porHora,
            'topFornecedores' => $topFornecedores,
            'fornecedoresPorMotivo' => $fornecedoresPorMotivo,
            'reincidentes' => $reincidentes,
            'rankingUsuarios' => $rankingUsuarios,
            'pendentesMaisAntigas' => $pendentesMaisAntigas,
            // ── Blocos novos ──
            'etapas'       => $this->etapas($de, $ate, $lojas, $origem, $ceasa, $contarPor),
            'retrabalho'   => $this->retrabalho($de, $ate, $lojas, $origem, $ceasa, $contarPor),
            'porOrigem'    => $this->porOrigem($de, $ate, $lojas, $ceasa, $contarPor),
            'porCeasa'     => $this->porCeasa($de, $ate, $lojas, $origem, $contarPor),
            'cancelamento' => $this->cancelamento($de, $ate, $lojas, $origem, $ceasa, $contarPor),
        ];
    }

    // ─── Blocos ───────────────────────────────────────────────────────────────

    /** Onde o tempo é gasto: análise do pré-lote, correção de compras, reconferência. */
    private function etapas($de, $ate, array $lojas, ?string $origem, ?string $ceasa, string $contarPor): array
    {
        // 1. Lançamento → primeiro card (o pré-lote analisou e achou divergência)
        $ateAnalise = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'cards.created_at')} as m")
            ->value('m');

        // 2. Card aberto → corrigido por compras
        $correcao = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->whereNotNull('cards.corrigido_em')
            ->selectRaw("{$this->avgMinutos('cards.created_at', 'cards.corrigido_em')} as m")
            ->value('m');

        // 3. Última correção → liberação (a reconferência final do pré-lote)
        $reconferencia = $this->base($de, $ate, $lojas, $origem, $ceasa, $contarPor)
            ->whereNotNull('liberada_em')
            ->whereHas('cards', fn($q) => $q->whereNotNull('corrigido_em'))
            ->join('cards', 'cards.nota_id', '=', 'notas.id')
            ->selectRaw("{$this->avgMinutos('cards.corrigido_em', 'notas.liberada_em')} as m")
            ->value('m');

        // 4. Lançamento → liberação, para notas SEM divergência (o caminho limpo)
        $semCard = $this->base($de, $ate, $lojas, $origem, $ceasa, $contarPor)
            ->whereNotNull('liberada_em')->whereDoesntHave('cards')
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")
            ->value('m');

        $h = fn($min) => $min !== null ? round($min / 60, 1) : null;

        return [
            'ateAnalise'    => $h($ateAnalise),
            'correcao'      => $h($correcao),
            'reconferencia' => $h($reconferencia),
            'semDivergencia' => $h($semCard),
        ];
    }

    /** Retrabalho: cards reabertos = "disse que corrigiu, mas continuava errado". */
    private function retrabalho($de, $ate, array $lojas, ?string $origem, ?string $ceasa, string $contarPor): array
    {
        $porTipo = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->where('cards.reaberturas', '>', 0)
            ->selectRaw('cards.tipo, COUNT(*) as cards, SUM(cards.reaberturas) as reaberturas')
            ->groupBy('cards.tipo')->orderByDesc('reaberturas')->get()
            ->map(fn($r) => [
                'motivo' => Card::rotulo($r->tipo),
                'cards' => (int) $r->cards, 'reaberturas' => (int) $r->reaberturas,
            ]);

        $porFornecedor = $this->cardsBase($de, $ate, $lojas, $origem, $ceasa)
            ->join('fornecedores', 'fornecedores.id', '=', 'notas.fornecedor_id')
            ->where('cards.reaberturas', '>', 0)
            ->selectRaw('fornecedores.nome as fornecedor, SUM(cards.reaberturas) as reaberturas')
            ->groupBy('fornecedores.nome')->orderByDesc('reaberturas')->limit(8)->get()
            ->map(fn($r) => ['fornecedor' => $r->fornecedor, 'total' => (int) $r->reaberturas]);

        $totalCards = (clone $this->cardsBase($de, $ate, $lojas, $origem, $ceasa))->count();
        $reabertos  = (clone $this->cardsBase($de, $ate, $lojas, $origem, $ceasa))->where('cards.reaberturas', '>', 0)->count();

        return [
            'totalCards'  => $totalCards,
            'reabertos'   => $reabertos,
            'taxa'        => $totalCards > 0 ? round(($reabertos / $totalCards) * 100, 1) : 0,
            'porTipo'     => $porTipo,
            'porFornecedor' => $porFornecedor,
        ];
    }

    /** Caminhão na porta × antecipada: são operações diferentes. */
    private function porOrigem($de, $ate, array $lojas, ?string $ceasa, string $contarPor): array
    {
        return collect(Nota::ORIGENS)->map(function ($o) use ($de, $ate, $lojas, $ceasa, $contarPor) {
            $q = $this->base($de, $ate, $lojas, $o, $ceasa, $contarPor);
            $total = (clone $q)->count();
            $liberadas = (clone $q)->whereNotNull('liberada_em')->count();
            $tempo = (clone $q)->whereNotNull('liberada_em')
                ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")->value('m');

            return [
                'origem' => Nota::ORIGEM_LABEL[$o],
                'total' => $total,
                'atendidas' => $liberadas,
                'taxa' => $total > 0 ? round(($liberadas / $total) * 100, 1) : 0,
                'tempoMedioHoras' => $tempo ? round($tempo / 60, 1) : null,
            ];
        })->all();
    }

    /** CEASA tem dinâmica própria (peso/preço variam) e distorce a média geral. */
    private function porCeasa($de, $ate, array $lojas, ?string $origem, string $contarPor): array
    {
        return collect(['sem' => 'Fora do CEASA', 'so' => 'CEASA'])->map(function ($rotulo, $filtro) use ($de, $ate, $lojas, $origem, $contarPor) {
            $q = $this->base($de, $ate, $lojas, $origem, $filtro, $contarPor);
            $total = (clone $q)->count();
            $liberadas = (clone $q)->whereNotNull('liberada_em')->count();
            $comCard = (clone $q)->whereHas('cards')->count();
            $tempo = (clone $q)->whereNotNull('liberada_em')
                ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")->value('m');

            return [
                'grupo' => $rotulo,
                'total' => $total,
                'atendidas' => $liberadas,
                'comDivergencia' => $comCard,
                'taxaDivergencia' => $total > 0 ? round(($comCard / $total) * 100, 1) : 0,
                'tempoMedioHoras' => $tempo ? round($tempo / 60, 1) : null,
            ];
        })->values()->all();
    }

    /** Cancelamento (docs/NOTAS_CANCELADAS.md). Só aqui as canceladas entram. */
    private function cancelamento($de, $ate, array $lojas, ?string $origem, ?string $ceasa, string $contarPor): array
    {
        $canceladas = Nota::whereNotNull('cancelada_em')
            ->whereBetween('cancelada_em', [$de, $ate])
            ->when($lojas, fn($q) => $q->whereIn('loja', $lojas))
            ->when($origem, fn($q) => $q->where('origem', $origem))
            ->when($ceasa === 'so', fn($q) => $q->where('ceasa', '>', 0))
            ->when($ceasa === 'sem', fn($q) => $q->where('ceasa', 0));

        $total = (clone $canceladas)->count();
        $liberadasNoPeriodo = $this->base($de, $ate, $lojas, $origem, $ceasa, $contarPor)
            ->whereNotNull('liberada_em')->count();

        // Cancelamento tardio: já tinha divergência resolvida quando foi cancelada
        $tardias = (clone $canceladas)->whereHas('cards')->count();

        $tempoAteCancelar = (clone $canceladas)
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'cancelada_em')} as m")->value('m');

        $porFornecedor = (clone $canceladas)
            ->selectRaw('fornecedor_id, COUNT(*) as total')
            ->with('fornecedor:id,nome')
            ->groupBy('fornecedor_id')->orderByDesc('total')->limit(8)->get()
            ->map(fn($r) => ['fornecedor' => $r->fornecedor->nome ?? '—', 'total' => (int) $r->total]);

        $porQuem = (clone $canceladas)
            ->selectRaw('cancelada_por, COUNT(*) as total')
            ->with('canceladaPor:id,name')
            ->groupBy('cancelada_por')->orderByDesc('total')->limit(8)->get()
            ->map(fn($r) => ['usuario' => $r->canceladaPor->name ?? '—', 'total' => (int) $r->total]);

        $den = $liberadasNoPeriodo + $total;

        return [
            'total' => $total,
            'taxa'  => $den > 0 ? round(($total / $den) * 100, 1) : 0,
            'tardias' => $tardias,
            'tempoMedioHoras' => $tempoAteCancelar ? round($tempoAteCancelar / 60, 1) : null,
            'porFornecedor' => $porFornecedor,
            'porQuem' => $porQuem,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Notas ATIVAS do período com os filtros aplicados. */
    /**
     * O recorte da tela, e por qual PONTA ele conta.
     *
     * Uma nota antecipada no pré-lote entra num dia e sai noutro, então as duas
     * perguntas dão números diferentes sem nada estar errado — num dia real,
     * 186 lançadas contra 256 liberadas. A tela contava só pela entrada, e por
     * isso discordava da planilha de "Liberadas neste dia".
     *
     *   lancadas  (padrão) — o que ENTROU no período. Responde "quanto chegou" e
     *                        deixa ver quanto disso ainda está na fila.
     *   liberadas          — o que SAIU no período, venha de quando vier. É a
     *                        mesma conta da planilha de liberadas.
     *
     * Ficou como filtro, ao lado de loja e fila, porque as duas perguntas são
     * legítimas e nenhuma serve para tudo: quem mede vazão quer a saída, quem
     * mede acúmulo quer a entrada.
     */
    private function base($de, $ate, array $lojas, ?string $origem, ?string $ceasa, string $contarPor = 'lancadas')
    {
        // 'liberadas' conta pela SAIDA; o padrao conta pela ENTRADA.
        $coluna = $contarPor === 'liberadas' ? 'notas.liberada_em' : 'notas.created_at';

        return Nota::ativas()
            ->whereBetween($coluna, [$de, $ate])
            ->when($lojas, fn($q) => $q->whereIn('loja', $lojas))
            ->when($origem, fn($q) => $q->where('origem', $origem))
            ->when($ceasa === 'so', fn($q) => $q->where('ceasa', '>', 0))
            ->when($ceasa === 'sem', fn($q) => $q->where('ceasa', 0));
    }

    /**
     * Cards das notas ativas do período. JOIN manual não respeita SoftDeletes
     * nem o escopo ativas() — por isso os dois filtros vão explícitos aqui.
     */
    private function cardsBase($de, $ate, array $lojas, ?string $origem, ?string $ceasa)
    {
        return Card::join('notas', 'notas.id', '=', 'cards.nota_id')
            ->whereNull('notas.deleted_at')
            ->whereNull('notas.cancelada_em')
            ->whereBetween('notas.created_at', [$de, $ate])
            ->when($lojas, fn($q) => $q->whereIn('notas.loja', $lojas))
            ->when($origem, fn($q) => $q->where('notas.origem', $origem))
            ->when($ceasa === 'so', fn($q) => $q->where('notas.ceasa', '>', 0))
            ->when($ceasa === 'sem', fn($q) => $q->where('notas.ceasa', 0));
    }

    /**
     * O recorte de ENTRADA: o que foi lançado no período.
     *
     * Só dois números precisam dele — "ainda na fila" e "taxa de liberação" —, e
     * os dois seriam mentira sobre a base de saída: ali toda nota já saiu, entao
     * a fila daria zero e a taxa daria 100%.
     */
    private function baseLancadas($de, $ate, array $lojas, ?string $origem, ?string $ceasa)
    {
        return Nota::ativas()
            ->whereBetween('notas.created_at', [$de, $ate])
            ->when($lojas, fn($q) => $q->whereIn('loja', $lojas))
            ->when($origem, fn($q) => $q->where('origem', $origem))
            ->when($ceasa === 'so', fn($q) => $q->where('ceasa', '>', 0))
            ->when($ceasa === 'sem', fn($q) => $q->where('ceasa', 0));
    }

    private function kpis($base, $lancadas): array
    {
        // O numero principal: quantas linhas o recorte escolhido tem.
        $total = (clone $base)->count();
        $liberadas = (clone $base)->whereNotNull('liberada_em')->count();

        // Dessas, quantas entraram e sairam no mesmo dia — o giro.
        $noMesmoDia = (clone $base)->whereNotNull('liberada_em')
            ->whereRaw('DATE(notas.created_at) = DATE(liberada_em)')->count();

        $tempo = (clone $base)->whereNotNull('liberada_em')
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")->value('m');

        /*
         * Os dois de ENTRADA. Vem da outra base de proposito: sobre as liberadas
         * do periodo, "na fila" daria sempre zero e a taxa sempre 100% — nao por
         * estarem certos, mas por a pergunta nao existir ali.
         */
        $lancadasTotal = (clone $lancadas)->count();
        $lancadasQueSairam = (clone $lancadas)->whereNotNull('liberada_em')->count();

        return [
            // O que o recorte escolhido contou: lancadas OU liberadas.
            'total' => $total,
            'atendidas' => $liberadas,
            'resolvidasNoDia' => $noMesmoDia,
            'tempoMedioHoras' => $tempo ? round($tempo / 60, 1) : null,

            /*
             * Estes dois vem SEMPRE da entrada, em qualquer recorte.
             *
             * No recorte "liberadas" toda nota ja saiu: a fila daria zero e a
             * taxa daria 100% — nao por estarem certos, mas por a pergunta nao
             * existir ali. Vindo da entrada, os dois querem dizer a mesma coisa
             * nos dois modos.
             */
            'lancadas' => $lancadasTotal,
            'pendentes' => $lancadasTotal - $lancadasQueSairam,
            'taxaResolucao' => $lancadasTotal > 0
                ? round(($lancadasQueSairam / $lancadasTotal) * 100, 1)
                : 0,
        ];
    }

    /** Variação percentual entre dois números (null quando não dá para comparar). */
    private function variacao(?float $atual, ?float $anterior): ?float
    {
        if ($atual === null || $anterior === null || $anterior == 0) {
            return null;
        }

        return round((($atual - $anterior) / $anterior) * 100, 1);
    }

    /**
     * Cache curto (5 min): a página é pesada para a VM e ninguém precisa dela
     * em tempo real. Em testes roda direto, para as asserções verem o dado novo.
     */
    private function cacheado(string $chave, callable $fn): array
    {
        if (app()->environment('testing')) {
            return $fn();
        }

        return Cache::remember($chave, now()->addMinutes(5), $fn);
    }
}
