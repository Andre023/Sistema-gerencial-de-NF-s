<?php

namespace App\Http\Controllers;

use App\Events\CadastroAtualizado;
use App\Events\RequisicaoAtualizada;
use App\Models\Cadastro;
use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\RequisicaoAuditoria;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CadastroController extends Controller
{
    // ─── INDEX ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $dataFiltro = $request->input('data', Carbon::today()->toDateString());

        $motivo     = $request->input('motivo');
        $fornecedor = $request->input('fornecedor');
        $busca      = $request->input('busca');
        $loja       = $request->input('loja');

        $base = Cadastro::with(['fornecedor:id,nome', 'user:id,name'])
            ->when($motivo,     fn($q) => $q->where('motivo', $motivo))
            ->when($loja,       fn($q) => $q->where('loja', $loja))
            ->when($fornecedor, fn($q) => $q->where('fornecedor_id', $fornecedor))
            ->when($busca, fn($q) => $q->where(function ($q) use ($busca) {
                $q->where('numero_nota', 'like', "%{$busca}%")
                    ->orWhereHas('fornecedor', fn($q2) => $q2->where('nome', 'like', "%{$busca}%"));
            }));

        $pendentes = (clone $base)
            ->where('status', 'Pendente')
            ->whereDate('created_at', '<=', $dataFiltro)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($c) => $this->formatCadastro($c, $dataFiltro));

        $atendidas = (clone $base)
            ->where('status', 'Atendida')
            ->whereDate('updated_at', $dataFiltro)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn($c) => $this->formatCadastro($c, $dataFiltro));

        $fornecedores = Fornecedor::select('id', 'nome')->orderBy('nome')->get();

        return Inertia::render('Cadastros/Index', [
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
                'motivos' => Cadastro::MOTIVOS,
                'lojas'   => Cadastro::LOJAS,
                'status'  => Cadastro::STATUS,
            ],
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'numero_nota'   => 'required|string|max:30',
            'fornecedor_id' => 'required|exists:fornecedores,id',
            'loja'          => 'required|integer|in:' . implode(',', Cadastro::LOJAS),
            'motivo'        => 'required|string|in:' . implode(',', Cadastro::MOTIVOS),
            'observacao'    => 'nullable|string|max:500',
        ]);

        $dados['user_id'] = $request->user()->id;
        $dados['status']  = 'Pendente';

        // Se for "Caminhão na Porta", cria também uma requisição com motivo "Pedido"
        if ($dados['motivo'] === 'Caminhão na Porta') {
            $requisicao = Requisicao::create([
                'numero_nota'   => $dados['numero_nota'],
                'fornecedor_id' => $dados['fornecedor_id'],
                'user_id'       => $dados['user_id'],
                'loja'          => $dados['loja'],
                'motivo'        => 'Pedido',
                'observacao'    => $dados['observacao'] ?? null,
                'status'        => 'Pendente',
            ]);

            RequisicaoAuditoria::create([
                'requisicao_id'    => $requisicao->id,
                'user_id'          => $request->user()->id,
                'acao'             => 'criada',
                'dados_anteriores' => null,
                'dados_novos'      => $requisicao->toArray(),
            ]);

            $dados['requisicao_id'] = $requisicao->id;

            event(new RequisicaoAtualizada());
        }

        Cadastro::create($dados);

        event(new CadastroAtualizado());

        return back()->with('sucesso', 'Cadastro criado com sucesso.');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function update(Request $request, Cadastro $cadastro): RedirectResponse
    {
        $dados = $request->validate([
            'numero_nota'   => 'sometimes|string|max:30',
            'fornecedor_id' => 'sometimes|exists:fornecedores,id',
            'loja'          => 'sometimes|integer|in:' . implode(',', Cadastro::LOJAS),
            'motivo'        => 'sometimes|string|in:' . implode(',', Cadastro::MOTIVOS),
            'observacao'    => 'nullable|string|max:500',
            'status'        => 'sometimes|string|in:' . implode(',', Cadastro::STATUS),
        ]);

        $motivoAnterior = $cadastro->motivo;

        // Se está mudando para "Caminhão na Porta" e ainda não tinha requisição vinculada
        if (
            isset($dados['motivo']) &&
            $dados['motivo'] === 'Caminhão na Porta' &&
            $motivoAnterior !== 'Caminhão na Porta' &&
            $cadastro->requisicao_id === null
        ) {
            $requisicao = Requisicao::create([
                'numero_nota'   => $dados['numero_nota']   ?? $cadastro->numero_nota,
                'fornecedor_id' => $dados['fornecedor_id'] ?? $cadastro->fornecedor_id,
                'user_id'       => $request->user()->id,
                'loja'          => $dados['loja']          ?? $cadastro->loja,
                'motivo'        => 'Pedido',
                'observacao'    => $dados['observacao']    ?? $cadastro->observacao,
                'status'        => 'Pendente',
            ]);

            RequisicaoAuditoria::create([
                'requisicao_id'    => $requisicao->id,
                'user_id'          => $request->user()->id,
                'acao'             => 'criada',
                'dados_anteriores' => null,
                'dados_novos'      => $requisicao->toArray(),
            ]);

            $dados['requisicao_id'] = $requisicao->id;

            event(new RequisicaoAtualizada());
        }

        $cadastro->update($dados);

        event(new CadastroAtualizado());

        return back()->with('sucesso', 'Cadastro atualizado.');
    }

    // ─── DESTROY ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, Cadastro $cadastro): RedirectResponse
    {
        // Se tinha requisição vinculada (era "Caminhão na Porta"), muda o motivo para "Cadastro"
        if ($cadastro->requisicao_id) {
            $req = Requisicao::find($cadastro->requisicao_id);
            if ($req && $req->status === 'Pendente') {
                $anterior = $req->toArray();
                $req->update(['motivo' => 'Cadastro']);

                RequisicaoAuditoria::create([
                    'requisicao_id'    => $req->id,
                    'user_id'          => $request->user()->id,
                    'acao'             => 'editada',
                    'dados_anteriores' => $anterior,
                    'dados_novos'      => $req->fresh()->toArray(),
                ]);

                event(new RequisicaoAtualizada());
            }
        }

        $cadastro->delete();

        event(new CadastroAtualizado());

        return back()->with('sucesso', 'Cadastro removido.');
    }

    // ─── ATENDER ──────────────────────────────────────────────────────────────

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function formatCadastro(Cadastro $c, string $dataFiltro): array
    {
        return [
            'id'             => $c->id,
            'numero_nota'    => $c->numero_nota,
            'fornecedor'     => $c->fornecedor,
            'user'           => $c->user,
            'loja'           => $c->loja,
            'motivo'         => $c->motivo,
            'observacao'     => $c->observacao,
            'status'         => $c->status,
            'requisicao_id'  => $c->requisicao_id,
            'created_at'     => $c->created_at,
            'updated_at'     => $c->updated_at,
            'atrasada'       => $c->isAtrasada($dataFiltro),
            'data_origem'    => $c->created_at->format('d/m'),
        ];
    }
}
