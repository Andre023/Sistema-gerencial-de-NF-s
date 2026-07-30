<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dossiê do fornecedor: tudo que a operação sabe sobre UM fornecedor.
 *
 * A página de Estatísticas responde "como está a operação?"; aqui a pergunta é
 * "esse fornecedor é bom ou ruim?" — a que o comprador faz antes de ligar para
 * ele. Por isso cada número vem comparado com a MÉDIA DA REDE: saber que ele
 * tem 12% de erro de custo só vira decisão quando se sabe que a média é 4%.
 *
 * Como em Estatísticas, notas canceladas e excluídas ficam fora dos números de
 * trabalho — o cancelamento tem bloco próprio.
 */
class DossieController extends Controller
{
    use \App\Support\SqlData;

    public function index(Request $request): Response
    {
        $busca = trim((string) $request->input('busca', ''));
        $id    = $request->input('fornecedor_id');
        $periodo = max(1, min((int) $request->input('periodo', 90), 365));

        $de  = now()->subDays($periodo - 1)->startOfDay();
        $ate = now()->endOfDay();

        // Busca por nome (limitada — são ~2.700 fornecedores)
        $resultados = $busca !== ''
            ? Fornecedor::where('nome', 'like', "%{$busca}%")
                ->orderBy('nome')->limit(20)->get(['id', 'nome', 'cnpj', 'prioridade'])
            : collect();

        // Se a busca achou um só, abre direto — poupa um clique
        if (! $id && $resultados->count() === 1) {
            $id = $resultados->first()->id;
        }

        $fornecedor = $id ? Fornecedor::find($id) : null;

        return Inertia::render('Dossie/Index', [
            'busca'      => $busca,
            'periodo'    => $periodo,
            'resultados' => $resultados,
            'fornecedor' => $fornecedor,
            'dossie'     => $fornecedor ? $this->montar($fornecedor, $de, $ate) : null,
        ]);
    }

    // ─── Montagem ─────────────────────────────────────────────────────────────

    private function montar(Fornecedor $f, $de, $ate): array
    {
        $notas = fn() => Nota::ativas()->where('fornecedor_id', $f->id)
            ->whereBetween('notas.created_at', [$de, $ate]);

        $total     = $notas()->count();
        $liberadas = $notas()->whereNotNull('liberada_em')->count();
        $comCard   = $notas()->whereHas('cards')->count();
        $semCard   = $total - $comCard;

        $tempo = $notas()->whereNotNull('liberada_em')
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")->value('m');

        // Canceladas (fora do total de trabalho, mas contam para a reputação)
        $canceladas = Nota::whereNotNull('cancelada_em')
            ->where('fornecedor_id', $f->id)
            ->whereBetween('cancelada_em', [$de, $ate])->count();

        // ── Índice de qualidade: % de notas que passaram limpas ───────────────
        $qualidade = $total > 0 ? round(($semCard / $total) * 100, 1) : null;

        // ── Média da REDE no mesmo período (a régua de comparação) ────────────
        $totalRede   = Nota::ativas()->whereBetween('notas.created_at', [$de, $ate])->count();
        $comCardRede = Nota::ativas()->whereBetween('notas.created_at', [$de, $ate])->whereHas('cards')->count();
        $qualidadeRede = $totalRede > 0 ? round((($totalRede - $comCardRede) / $totalRede) * 100, 1) : null;

        $tempoRede = Nota::ativas()->whereBetween('notas.created_at', [$de, $ate])
            ->whereNotNull('liberada_em')
            ->selectRaw("{$this->avgMinutos('notas.created_at', 'liberada_em')} as m")->value('m');

        // ── Divergências por tipo: dele × média da rede ───────────────────────
        $doFornecedor = Card::join('notas', 'notas.id', '=', 'cards.nota_id')
            ->whereNull('notas.deleted_at')->whereNull('notas.cancelada_em')
            ->where('notas.fornecedor_id', $f->id)
            ->whereBetween('notas.created_at', [$de, $ate])
            ->selectRaw('cards.tipo, COUNT(*) as total')
            ->groupBy('cards.tipo')->pluck('total', 'cards.tipo');

        $daRede = Card::join('notas', 'notas.id', '=', 'cards.nota_id')
            ->whereNull('notas.deleted_at')->whereNull('notas.cancelada_em')
            ->whereBetween('notas.created_at', [$de, $ate])
            ->selectRaw('cards.tipo, COUNT(*) as total')
            ->groupBy('cards.tipo')->pluck('total', 'cards.tipo');

        $divergencias = collect(Card::TIPOS)->map(function ($tipo) use ($doFornecedor, $daRede, $total, $totalRede) {
            $qtd = (int) ($doFornecedor[$tipo] ?? 0);
            // Taxa por 100 notas — só assim dá para comparar quem tem volumes diferentes
            $taxa     = $total > 0 ? round(($qtd / $total) * 100, 1) : 0;
            $taxaRede = $totalRede > 0 ? round(((int) ($daRede[$tipo] ?? 0) / $totalRede) * 100, 1) : 0;

            return [
                'motivo'   => ucfirst(str_replace('_', ' ', $tipo)),
                'total'    => $qtd,
                'taxa'     => $taxa,
                'taxaRede' => $taxaRede,
                // Quantas vezes acima (ou abaixo) da média da rede
                'vezes'    => $taxaRede > 0 ? round($taxa / $taxaRede, 1) : null,
            ];
        })->filter(fn($d) => $d['total'] > 0)->values();

        // ── Evolução mensal ───────────────────────────────────────────────────
        $evolucao = $notas()
            ->selectRaw("{$this->anoMes('notas.created_at')} as mes, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as liberadas")
            ->groupBy('mes')->orderBy('mes')->get()
            ->map(fn($r) => ['mes' => $r->mes, 'total' => (int) $r->total, 'liberadas' => (int) $r->liberadas]);

        // Divergências por mês (para ver se está melhorando ou piorando)
        $divergenciaMensal = Card::join('notas', 'notas.id', '=', 'cards.nota_id')
            ->whereNull('notas.deleted_at')->whereNull('notas.cancelada_em')
            ->where('notas.fornecedor_id', $f->id)
            ->whereBetween('notas.created_at', [$de, $ate])
            ->selectRaw("{$this->anoMes('notas.created_at')} as mes, COUNT(*) as total")
            ->groupBy('mes')->orderBy('mes')->pluck('total', 'mes');

        $evolucao = $evolucao->map(fn($e) => [...$e, 'divergencias' => (int) ($divergenciaMensal[$e['mes']] ?? 0)]);

        // ── Por loja ──────────────────────────────────────────────────────────
        $porLoja = $notas()
            ->selectRaw('loja, COUNT(*) as total')
            ->groupBy('loja')->orderBy('loja')->get()
            ->map(fn($r) => ['loja' => (int) $r->loja, 'total' => (int) $r->total]);

        // ── Retrabalho ────────────────────────────────────────────────────────
        $reaberturas = Card::join('notas', 'notas.id', '=', 'cards.nota_id')
            ->whereNull('notas.deleted_at')->whereNull('notas.cancelada_em')
            ->where('notas.fornecedor_id', $f->id)
            ->whereBetween('notas.created_at', [$de, $ate])
            ->sum('cards.reaberturas');

        // ── Últimas notas ─────────────────────────────────────────────────────
        $hojeStr = now()->toDateString();
        $ultimas = Nota::where('fornecedor_id', $f->id)
            ->with(['cards', 'user:id,name'])
            ->orderByDesc('created_at')->limit(15)->get()
            ->map(fn($n) => [
                'id' => $n->id,
                'numero_nota' => $n->numero_nota,
                'loja' => $n->loja,
                'status' => $n->statusCalculado(),
                'origem' => $n->origem,
                'ceasa' => (int) $n->ceasa,
                'cards' => $n->cards->map(fn($c) => [
                    'tipo' => $c->tipo, 'status' => $c->status, 'reaberturas' => $c->reaberturas,
                ])->values(),
                'dias_aberta' => $n->diasEmAberto($hojeStr),
                'created_at'  => $n->created_at->format('d/m/Y'),
                'liberada_em' => $n->liberada_em?->format('d/m/Y'),
            ]);

        return [
            'kpis' => [
                'total' => $total,
                'liberadas' => $liberadas,
                'canceladas' => $canceladas,
                'qualidade' => $qualidade,
                'qualidadeRede' => $qualidadeRede,
                'tempoMedioHoras' => $tempo ? round($tempo / 60, 1) : null,
                'tempoRedeHoras'  => $tempoRede ? round($tempoRede / 60, 1) : null,
                'reaberturas' => (int) $reaberturas,
                'taxaCancelamento' => ($liberadas + $canceladas) > 0
                    ? round(($canceladas / ($liberadas + $canceladas)) * 100, 1) : 0,
            ],
            'divergencias' => $divergencias,
            'evolucao'     => $evolucao,
            'porLoja'      => $porLoja,
            'ultimas'      => $ultimas,
        ];
    }
}
