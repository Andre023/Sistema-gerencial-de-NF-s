<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\RequisicaoAuditoria;
use App\Events\RequisicaoAtualizada;
use App\Http\Requests\RequisicaoRequest;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $nivel      = $request->input('nivel');

        if (! in_array($nivel, Requisicao::NIVEIS_ALERTA, true)) {
            $nivel = null;
        }

        $base = Requisicao::with(['fornecedor:id,nome', 'user:id,name', 'atendidaPor:id,name'])
            ->withCount('comentarios')
            ->when($motivo,     fn($q) => $q->where('motivo', $motivo))
            ->when($loja,       fn($q) => $q->where('loja', $loja))
            ->when($fornecedor, fn($q) => $q->where('fornecedor_id', $fornecedor))
            ->when($busca, fn($q) => $q->where(function ($q) use ($busca) {
                $q->where('numero_nota', 'like', "%{$busca}%")
                    ->orWhereHas('fornecedor', fn($q2) => $q2->where('nome', 'like', "%{$busca}%"));
            }));

        // PENDENTES: todas até a data filtrada (inclusive arrastadas de dias anteriores)
        $pendentesTodas = (clone $base)
            ->where('status', 'Pendente')
            ->whereDate('created_at', '<=', $dataFiltro)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($r) => $this->formatRequisicao($r, $dataFiltro));

        // Contadores do banner: contam antes do filtro de nível, senão clicar num
        // nível zeraria os demais e o panorama se perderia.
        $resumoAlertas = [
            Requisicao::NIVEL_CRITICO => $pendentesTodas->where('nivel', Requisicao::NIVEL_CRITICO)->count(),
            Requisicao::NIVEL_ALERTA  => $pendentesTodas->where('nivel', Requisicao::NIVEL_ALERTA)->count(),
            Requisicao::NIVEL_ATENCAO => $pendentesTodas->where('nivel', Requisicao::NIVEL_ATENCAO)->count(),
        ];

        $pendentes = $nivel
            ? $pendentesTodas->where('nivel', $nivel)->values()
            : $pendentesTodas;

        // ATENDIDAS: somente as resolvidas no dia exato (pela data de atendimento,
        // não pelo updated_at — que muda a cada edição posterior)
        $atendidas = (clone $base)
            ->where('status', 'Atendida')
            ->whereDate('atendida_em', $dataFiltro)
            ->orderBy('atendida_em', 'desc')
            ->get()
            ->map(fn($r) => $this->formatRequisicao($r, $dataFiltro));

        // Lista leve de fornecedores para o formulário
        $fornecedores = Fornecedor::select('id', 'nome')->orderBy('nome')->get();

        return Inertia::render('Requisicoes/Index', [
            'pendentes'     => $pendentes,
            'atendidas'     => $atendidas,
            'fornecedores'  => $fornecedores,
            'dataFiltro'    => $dataFiltro,
            'resumoAlertas' => $resumoAlertas,
            'filtros'      => [
                'motivo'     => $motivo,
                'fornecedor' => $fornecedor,
                'busca'      => $busca,
                'loja'       => $loja,
                'nivel'      => $nivel,
            ],
            'opcoes' => [
                'motivos' => Requisicao::MOTIVOS,
                'lojas'   => Requisicao::LOJAS,
                'status'  => Requisicao::STATUS,
                'sla'     => [
                    'atencao' => Requisicao::SLA_ATENCAO,
                    'alerta'  => Requisicao::SLA_ALERTA,
                    'critico' => Requisicao::SLA_CRITICO,
                ],
            ],
        ]);
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(RequisicaoRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        $dados['user_id'] = $request->user()->id;
        $dados['status']  = 'Pendente';

        DB::transaction(function () use ($dados, $request) {
            $req = Requisicao::create($dados);

            RequisicaoAuditoria::create([
                'requisicao_id'    => $req->id,
                'user_id'          => $request->user()->id,
                'acao'             => 'criada',
                'dados_anteriores' => null,
                'dados_novos'      => $req->toArray(),
            ]);
        });

        // AVISA TODOS OS COMPUTADORES ONLINE QUE HOUVE ATUALIZAÇÃO
        event(new RequisicaoAtualizada());

        return back()->with('sucesso', 'Requisição criada com sucesso.');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function update(RequisicaoRequest $request, Requisicao $requisicao): RedirectResponse
    {
        // Autorização (atender x editar) fica em RequisicaoRequest::authorize().
        $dados = $request->validated();

        // Registra quem atendeu e quando (ou limpa, se voltou a Pendente)
        if (isset($dados['status'])) {
            if ($dados['status'] === 'Atendida' && $requisicao->status !== 'Atendida') {
                $dados['atendida_por'] = $request->user()->id;
                $dados['atendida_em']  = now();
            } elseif ($dados['status'] === 'Pendente') {
                $dados['atendida_por'] = null;
                $dados['atendida_em']  = null;
            }
        }

        $anterior = $requisicao->toArray();

        DB::transaction(function () use ($requisicao, $dados, $anterior, $request) {
            $requisicao->update($dados);

            $acao = isset($dados['status']) && $dados['status'] === 'Atendida' ? 'atendida' : 'editada';

            RequisicaoAuditoria::create([
                'requisicao_id'    => $requisicao->id,
                'user_id'          => $request->user()->id,
                'acao'             => $acao,
                'dados_anteriores' => $anterior,
                'dados_novos'      => $requisicao->fresh()->toArray(),
            ]);
        });

        // AVISA TODOS OS COMPUTADORES ONLINE QUE HOUVE ATUALIZAÇÃO
        event(new RequisicaoAtualizada());

        return back()->with('sucesso', 'Requisição atualizada.');
    }

    // ─── DESTROY (soft delete) ────────────────────────────────────────────────

    public function destroy(Request $request, Requisicao $requisicao): RedirectResponse
    {
        Gate::authorize('gerenciar-registros'); // excluir — encarregado ou admin

        DB::transaction(function () use ($requisicao, $request) {
            RequisicaoAuditoria::create([
                'requisicao_id'    => $requisicao->id,
                'user_id'          => $request->user()->id,
                'acao'             => 'excluida',
                'dados_anteriores' => $requisicao->toArray(),
                'dados_novos'      => null,
            ]);

            $requisicao->delete(); // SoftDelete — não apaga do banco
        });

        // AVISA TODOS OS COMPUTADORES ONLINE QUE HOUVE ATUALIZAÇÃO
        event(new RequisicaoAtualizada());

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
            'atendida_por' => $r->atendidaPor,
            'atendida_em'  => $r->atendida_em,
            'comentarios_count' => $r->comentarios_count ?? 0,
            'created_at'   => $r->created_at,
            'updated_at'   => $r->updated_at,
            'atrasada'     => $r->isAtrasada($dataFiltro),
            'dias_aberta'  => $r->diasEmAberto($dataFiltro),
            'nivel'        => $r->nivelAlerta($dataFiltro),
            'data_origem'  => $r->created_at->format('d/m'),
        ];
    }
}
