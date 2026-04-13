<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\RequisicaoAuditoria;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisicaoController extends Controller
{
    // ─── INDEX ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $dataFiltro = $request->input('data', Carbon::today()->toDateString());

        // Filtros opcionais
        $motivo     = $request->input('motivo');
        $fornecedor = $request->input('fornecedor');
        $busca      = $request->input('busca');
        $loja       = $request->input('loja');

        $base = Requisicao::with(['fornecedor:id,nome', 'user:id,name'])
            ->when($motivo,     fn($q) => $q->where('motivo', $motivo))
            ->when($loja,       fn($q) => $q->where('loja', $loja))
            ->when($fornecedor, fn($q) => $q->where('fornecedor_id', $fornecedor))
            ->when($busca, fn($q) => $q->where(function ($q) use ($busca) {
                $q->where('numero_nota', 'like', "%{$busca}%")
                  ->orWhereHas('fornecedor', fn($q2) => $q2->where('nome', 'like', "%{$busca}%"));
            }));

        // PENDENTES: todas até a data filtrada (inclusive arrastadas de dias anteriores)
        $pendentes = (clone $base)
            ->where('status', 'Pendente')
            ->whereDate('created_at', '<=', $dataFiltro)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($r) => $this->formatRequisicao($r, $dataFiltro));

        // ATENDIDAS: somente as resolvidas no dia exato
        $atendidas = (clone $base)
            ->where('status', 'Atendida')
            ->whereDate('updated_at', $dataFiltro)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn($r) => $this->formatRequisicao($r, $dataFiltro));

        // Lista leve de fornecedores para o formulário
        $fornecedores = Fornecedor::select('id', 'nome')->orderBy('nome')->get();

        return Inertia::render('Requisicoes/Index', [
            'pendentes'    => $pendentes,
            'atendidas'    => $atendidas,
            'fornecedores' => $fornecedores,
            'dataFiltro'   => $dataFiltro,
            'filtros'      => [
                'motivo'     => $motivo,
                'fornecedor' => $fornecedor,
                'busca'      => $busca,
                'loja'       => $loja,
            ],
            'opcoes' => [
                'motivos' => Requisicao::MOTIVOS,
                'lojas'   => Requisicao::LOJAS,
                'status'  => Requisicao::STATUS,
            ],
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'numero_nota'   => 'required|string|max:30',
            'fornecedor_id' => 'required|exists:fornecedores,id',
            'loja'          => 'required|integer|in:' . implode(',', Requisicao::LOJAS),
            'motivo'        => 'required|string|in:' . implode(',', Requisicao::MOTIVOS),
            'observacao'    => 'nullable|string|max:500',
        ]);

        $dados['user_id'] = $request->user()->id;
        $dados['status']  = 'Pendente';

        $req = Requisicao::create($dados);

        RequisicaoAuditoria::create([
            'requisicao_id'   => $req->id,
            'user_id'         => $request->user()->id,
            'acao'            => 'criada',
            'dados_anteriores' => null,
            'dados_novos'     => $req->toArray(),
        ]);

        return back()->with('sucesso', 'Requisição criada com sucesso.');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function update(Request $request, Requisicao $requisicao): RedirectResponse
    {
        $dados = $request->validate([
            'numero_nota'   => 'sometimes|string|max:30',
            'fornecedor_id' => 'sometimes|exists:fornecedores,id',
            'loja'          => 'sometimes|integer|in:' . implode(',', Requisicao::LOJAS),
            'motivo'        => 'sometimes|string|in:' . implode(',', Requisicao::MOTIVOS),
            'observacao'    => 'nullable|string|max:500',
            'status'        => 'sometimes|string|in:' . implode(',', Requisicao::STATUS),
        ]);

        $anterior = $requisicao->toArray();

        $requisicao->update($dados);

        $acao = isset($dados['status']) && $dados['status'] === 'Atendida' ? 'atendida' : 'editada';

        RequisicaoAuditoria::create([
            'requisicao_id'    => $requisicao->id,
            'user_id'          => $request->user()->id,
            'acao'             => $acao,
            'dados_anteriores' => $anterior,
            'dados_novos'      => $requisicao->fresh()->toArray(),
        ]);

        return back()->with('sucesso', 'Requisição atualizada.');
    }

    // ─── DESTROY (soft delete) ────────────────────────────────────────────────

    public function destroy(Request $request, Requisicao $requisicao): RedirectResponse
    {
        RequisicaoAuditoria::create([
            'requisicao_id'    => $requisicao->id,
            'user_id'          => $request->user()->id,
            'acao'             => 'excluida',
            'dados_anteriores' => $requisicao->toArray(),
            'dados_novos'      => null,
        ]);

        $requisicao->delete(); // SoftDelete — não apaga do banco

        return back()->with('sucesso', 'Requisição removida.');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function formatRequisicao(Requisicao $r, string $dataFiltro): array
    {
        return [
            'id'           => $r->id,
            'numero_nota'  => $r->numero_nota,
            'fornecedor'   => $r->fornecedor,
            'user'         => $r->user,
            'loja'         => $r->loja,
            'motivo'       => $r->motivo,
            'observacao'   => $r->observacao,
            'status'       => $r->status,
            'created_at'   => $r->created_at,
            'updated_at'   => $r->updated_at,
            'atrasada'     => $r->isAtrasada($dataFiltro),
            'data_origem'  => $r->created_at->format('d/m'),
        ];
    }
}
