<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use App\Models\Requisicao;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EstatisticaController extends Controller
{
    public function index(Request $request): Response
    {
        $periodo = $request->input('periodo', 30); // Últimos N dias

        $de  = now()->subDays($periodo)->startOfDay();
        $ate = now()->endOfDay();

        $base = Requisicao::whereBetween('created_at', [$de, $ate]);

        // Total por motivo
        $porMotivo = (clone $base)
            ->selectRaw('motivo, COUNT(*) as total')
            ->groupBy('motivo')
            ->orderByDesc('total')
            ->get();

        // Total por loja
        $porLoja = (clone $base)
            ->selectRaw('loja, COUNT(*) as total')
            ->groupBy('loja')
            ->orderBy('loja')
            ->get();

        // Top fornecedores (geral)
        $topFornecedores = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total')
            ->with('fornecedor:id,nome')
            ->groupBy('fornecedor_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'fornecedor' => $r->fornecedor->nome ?? '—',
                'total'      => $r->total,
            ]);

        // Fornecedores por motivo (cross-analysis)
        $fornecedoresPorMotivo = [];
        foreach (Requisicao::MOTIVOS as $motivo) {
            $fornecedoresPorMotivo[$motivo] = (clone $base)
                ->where('motivo', $motivo)
                ->selectRaw('fornecedor_id, COUNT(*) as total')
                ->with('fornecedor:id,nome')
                ->groupBy('fornecedor_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'fornecedor' => $r->fornecedor->nome ?? '—',
                    'total'      => $r->total,
                ]);
        }

        // Taxa de resolução no dia (pendentes que viraram atendidas no mesmo dia)
        $totalPendentes = (clone $base)->count();
        $resolvidasNoDia = (clone $base)
            ->where('status', 'Atendida')
            ->whereRaw('DATE(created_at) = DATE(updated_at)')
            ->count();

        $taxaResolucao = $totalPendentes > 0
            ? round(($resolvidasNoDia / $totalPendentes) * 100, 1)
            : 0;

        // Evolução diária (últimos N dias)
        $evolucaoDiaria = (clone $base)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get();

        // Fornecedores reincidentes (mais de 3 requisições no período)
        $reincidentes = (clone $base)
            ->selectRaw('fornecedor_id, COUNT(*) as total')
            ->with('fornecedor:id,nome')
            ->groupBy('fornecedor_id')
            ->having('total', '>', 3)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'fornecedor' => $r->fornecedor->nome ?? '—',
                'total'      => $r->total,
            ]);

        return Inertia::render('Estatisticas/Index', [
            'periodo'             => (int) $periodo,
            'totais' => [
                'requisicoes'   => $totalPendentes,
                'resolvidasNoDia' => $resolvidasNoDia,
                'taxaResolucao' => $taxaResolucao,
            ],
            'porMotivo'           => $porMotivo,
            'porLoja'             => $porLoja,
            'topFornecedores'     => $topFornecedores,
            'fornecedoresPorMotivo' => $fornecedoresPorMotivo,
            'evolucaoDiaria'      => $evolucaoDiaria,
            'reincidentes'        => $reincidentes,
        ]);
    }
}
