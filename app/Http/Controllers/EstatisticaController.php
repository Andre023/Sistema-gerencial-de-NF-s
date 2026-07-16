<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Nota;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estatísticas sobre a fila de notas. As keys das props mantêm os nomes
 * genéricos (kpis, porMotivo...) para o frontend mudar pouco:
 * "atendida" = liberada; "motivo" = tipo de card (ou "sem divergência").
 */
class EstatisticaController extends Controller
{
    public function index(Request $request): Response
    {
        $periodo = (int) $request->input('periodo', 30);

        $de  = now()->subDays($periodo - 1)->startOfDay();
        $ate = now()->endOfDay();

        $base = Nota::whereBetween('created_at', [$de, $ate]);

        // ── KPIs principais ───────────────────────────────────────────────────
        $totalNotas     = (clone $base)->count();
        $totalLiberadas = (clone $base)->whereNotNull('liberada_em')->count();
        $totalPendentes = $totalNotas - $totalLiberadas;

        $resolvidasNoDia = (clone $base)
            ->whereNotNull('liberada_em')
            ->whereRaw('DATE(created_at) = DATE(liberada_em)')
            ->count();

        $taxaResolucao = $totalNotas > 0
            ? round(($totalLiberadas / $totalNotas) * 100, 1)
            : 0;

        // Tempo médio entre lançar e liberar (em horas)
        $tempoMedio = (clone $base)
            ->whereNotNull('liberada_em')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, liberada_em)) as media_minutos')
            ->value('media_minutos');
        $tempoMedioHoras = $tempoMedio ? round($tempoMedio / 60, 1) : null;

        // ── Evolução diária ───────────────────────────────────────────────────
        $evolucaoDiaria = (clone $base)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas,
                          SUM(CASE WHEN liberada_em IS NULL THEN 1 ELSE 0 END) as pendentes')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->map(fn($r) => [
                'dia'       => $r->dia,
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
                'pendentes' => (int) $r->pendentes,
            ]);

        // ── Por tipo de divergência (card) ────────────────────────────────────
        $porMotivo = Card::whereHas('nota', fn($q) => $q->whereBetween('created_at', [$de, $ate]))
            ->selectRaw('tipo as motivo, COUNT(*) as total,
                          SUM(CASE WHEN status = "resolvido" THEN 1 ELSE 0 END) as atendidas')
            ->groupBy('tipo')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'motivo'    => ucfirst($r->motivo),
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
            ]);

        // Notas limpas (liberadas sem nenhum card) entram como categoria própria
        $semDivergencia = (clone $base)
            ->whereNotNull('liberada_em')
            ->whereDoesntHave('cards')
            ->count();

        if ($semDivergencia > 0) {
            $porMotivo->push([
                'motivo'    => 'Sem divergência',
                'total'     => $semDivergencia,
                'atendidas' => $semDivergencia,
            ]);
        }

        // ── Por loja ──────────────────────────────────────────────────────────
        $porLoja = (clone $base)
            ->selectRaw('loja, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->groupBy('loja')
            ->orderBy('loja')
            ->get()
            ->map(fn($r) => [
                'loja'      => (int) $r->loja,
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
            ]);

        // ── Por dia da semana ─────────────────────────────────────────────────
        $porDiaSemana = (clone $base)
            ->selectRaw('DAYOFWEEK(created_at) as dia_num, COUNT(*) as total')
            ->groupBy('dia_num')
            ->orderBy('dia_num')
            ->get()
            ->map(fn($r) => [
                'dia'   => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][$r->dia_num - 1],
                'total' => (int) $r->total,
            ]);

        // ── Por hora do dia ───────────────────────────────────────────────────
        $porHora = (clone $base)
            ->selectRaw('HOUR(created_at) as hora, COUNT(*) as total')
            ->groupBy('hora')
            ->orderBy('hora')
            ->get()
            ->map(fn($r) => [
                'hora'  => str_pad($r->hora, 2, '0', STR_PAD_LEFT) . 'h',
                'total' => (int) $r->total,
            ]);

        // ── Top fornecedores geral ────────────────────────────────────────────
        $topFornecedores = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->with('fornecedor:id,nome')
            ->groupBy('fornecedor_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'fornecedor' => $r->fornecedor->nome ?? '—',
                'total'      => (int) $r->total,
                'atendidas'  => (int) $r->atendidas,
            ]);

        // ── Top fornecedores por tipo de divergência ──────────────────────────
        $fornecedoresPorMotivo = [];
        foreach (Card::TIPOS as $tipo) {
            $fornecedoresPorMotivo[ucfirst($tipo)] = Card::where('cards.tipo', $tipo)
                ->join('notas', 'notas.id', '=', 'cards.nota_id')
                ->join('fornecedores', 'fornecedores.id', '=', 'notas.fornecedor_id')
                ->whereBetween('notas.created_at', [$de, $ate])
                ->selectRaw('fornecedores.nome as fornecedor, COUNT(*) as total')
                ->groupBy('fornecedores.nome')
                ->orderByDesc('total')
                ->limit(8)
                ->get()
                ->map(fn($r) => [
                    'fornecedor' => $r->fornecedor,
                    'total'      => (int) $r->total,
                ]);
        }

        // ── Fornecedores reincidentes ─────────────────────────────────────────
        $reincidentes = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total,
                          COUNT(DISTINCT DATE(created_at)) as dias_distintos')
            ->with('fornecedor:id,nome')
            ->whereHas('cards')
            ->groupBy('fornecedor_id')
            ->having('total', '>', 2)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'fornecedor'     => $r->fornecedor->nome ?? '—',
                'total'          => (int) $r->total,
                'dias_distintos' => (int) $r->dias_distintos,
            ]);

        // ── Ranking de usuários (quem mais lançou) ────────────────────────────
        $rankingUsuarios = (clone $base)
            ->selectRaw('user_id, COUNT(*) as total,
                          SUM(CASE WHEN liberada_em IS NOT NULL THEN 1 ELSE 0 END) as atendidas')
            ->with('user:id,name')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'usuario'   => $r->user->name ?? '—',
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
            ]);

        // ── Pendentes mais antigas (top 10 travadas) ──────────────────────────
        $hojeStr = now()->toDateString();

        $pendentesMaisAntigas = Nota::whereNull('liberada_em')
            ->with(['fornecedor:id,nome', 'user:id,name', 'cards'])
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'numero_nota' => $n->numero_nota,
                'fornecedor'  => $n->fornecedor->nome ?? '—',
                'motivo'      => $n->cards->where('status', '!=', Card::STATUS_RESOLVIDO)
                                          ->pluck('tipo')->map(fn($t) => ucfirst($t))->implode(', ') ?: 'Sem divergência',
                'loja'        => $n->loja,
                'dias_aberta' => $n->diasEmAberto($hojeStr),
                'nivel'       => $n->nivelAlerta($hojeStr),
                'created_at'  => $n->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Estatisticas/Index', [
            'periodo'              => $periodo,
            'kpis' => [
                'total'            => $totalNotas,
                'atendidas'        => $totalLiberadas,
                'pendentes'        => $totalPendentes,
                'resolvidasNoDia'  => $resolvidasNoDia,
                'taxaResolucao'    => $taxaResolucao,
                'tempoMedioHoras'  => $tempoMedioHoras,
            ],
            'evolucaoDiaria'       => $evolucaoDiaria,
            'porMotivo'            => $porMotivo,
            'porLoja'              => $porLoja,
            'porDiaSemana'         => $porDiaSemana,
            'porHora'              => $porHora,
            'topFornecedores'      => $topFornecedores,
            'fornecedoresPorMotivo' => $fornecedoresPorMotivo,
            'reincidentes'         => $reincidentes,
            'rankingUsuarios'      => $rankingUsuarios,
            'pendentesMaisAntigas' => $pendentesMaisAntigas,
        ]);
    }
}
