<?php

namespace App\Http\Controllers;

use App\Models\Requisicao;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EstatisticaController extends Controller
{
    public function index(Request $request): Response
    {
        $periodo = (int) $request->input('periodo', 30);

        $de  = now()->subDays($periodo - 1)->startOfDay();
        $ate = now()->endOfDay();

        $base = Requisicao::whereBetween('created_at', [$de, $ate])->withTrashed();

        // ── KPIs principais ───────────────────────────────────────────────────
        $totalReqs     = (clone $base)->count();
        $totalAtendidas = (clone $base)->where('status', 'Atendida')->count();
        $totalPendentes = (clone $base)->where('status', 'Pendente')->count();

        $resolvidasNoDia = (clone $base)
            ->where('status', 'Atendida')
            ->whereRaw('DATE(created_at) = DATE(updated_at)')
            ->count();

        $taxaResolucao = $totalReqs > 0
            ? round(($totalAtendidas / $totalReqs) * 100, 1)
            : 0;

        // Tempo médio de resolução (em horas) — só das atendidas no período
        $tempoMedio = (clone $base)
            ->where('status', 'Atendida')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as media_minutos')
            ->value('media_minutos');
        $tempoMedioHoras = $tempoMedio ? round($tempoMedio / 60, 1) : null;

        // ── Evolução diária ───────────────────────────────────────────────────
        $evolucaoDiaria = (clone $base)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as total,
                          SUM(CASE WHEN status = "Atendida" THEN 1 ELSE 0 END) as atendidas,
                          SUM(CASE WHEN status = "Pendente" THEN 1 ELSE 0 END) as pendentes')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->map(fn($r) => [
                'dia'       => $r->dia,
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
                'pendentes' => (int) $r->pendentes,
            ]);

        // ── Por motivo ────────────────────────────────────────────────────────
        $porMotivo = (clone $base)
            ->selectRaw('motivo, COUNT(*) as total,
                          SUM(CASE WHEN status = "Atendida" THEN 1 ELSE 0 END) as atendidas')
            ->groupBy('motivo')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'motivo'    => $r->motivo,
                'total'     => (int) $r->total,
                'atendidas' => (int) $r->atendidas,
            ]);

        // ── Por loja ──────────────────────────────────────────────────────────
        $porLoja = (clone $base)
            ->selectRaw('loja, COUNT(*) as total,
                          SUM(CASE WHEN status = "Atendida" THEN 1 ELSE 0 END) as atendidas')
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
                          SUM(CASE WHEN status = "Atendida" THEN 1 ELSE 0 END) as atendidas')
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

        // ── Top fornecedores por motivo ───────────────────────────────────────
        $fornecedoresPorMotivo = [];
        foreach (Requisicao::MOTIVOS as $motivo) {
            $fornecedoresPorMotivo[$motivo] = (clone $base)
                ->where('motivo', $motivo)
                ->selectRaw('fornecedor_id, COUNT(*) as total')
                ->with('fornecedor:id,nome')
                ->groupBy('fornecedor_id')
                ->orderByDesc('total')
                ->limit(8)
                ->get()
                ->map(fn($r) => [
                    'fornecedor' => $r->fornecedor->nome ?? '—',
                    'total'      => (int) $r->total,
                ]);
        }

        // ── Fornecedores reincidentes ─────────────────────────────────────────
        $reincidentes = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total,
                          COUNT(DISTINCT DATE(created_at)) as dias_distintos')
            ->with('fornecedor:id,nome')
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
                          SUM(CASE WHEN status = "Atendida" THEN 1 ELSE 0 END) as atendidas')
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
        $pendentesMaisAntigas = Requisicao::where('status', 'Pendente')
            ->with(['fornecedor:id,nome', 'user:id,name'])
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'numero_nota' => $r->numero_nota,
                'fornecedor'  => $r->fornecedor->nome ?? '—',
                'motivo'      => $r->motivo,
                'loja'        => $r->loja,
                'dias_aberta' => (int) now()->diffInDays($r->created_at),
                'created_at'  => $r->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Estatisticas/Index', [
            'periodo'              => $periodo,
            'kpis' => [
                'total'            => $totalReqs,
                'atendidas'        => $totalAtendidas,
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
