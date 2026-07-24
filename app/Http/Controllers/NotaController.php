<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NotaController extends Controller
{
    // ─── INDEX ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $dataFiltro = $request->input('data', Carbon::today()->toDateString());

        $busca  = $request->input('busca');
        $loja   = $request->input('loja');
        $nivel  = $request->input('nivel');
        $status = $request->input('status');
        $tipo   = $request->input('tipo');

        if (! in_array($nivel, Nota::NIVEIS_ALERTA, true)) {
            $nivel = null;
        }
        // Por ora só filtramos pela fase "reconferir" (prontas p/ liberar)
        if ($status !== Nota::STATUS_RECONFERIR) {
            $status = null;
        }
        if (! in_array($tipo, Card::TIPOS, true)) {
            $tipo = null;
        }

        $base = Nota::with(['fornecedor:id,nome', 'user:id,name', 'liberadaPor:id,name', 'cards'])
            ->withCount('comentarios')
            ->when($loja, fn($q) => $q->where('loja', $loja))
            ->when($busca, fn($q) => $q->where(function ($q) use ($busca) {
                $q->where('numero_nota', 'like', "%{$busca}%")
                    ->orWhereHas('fornecedor', fn($q2) => $q2->where('nome', 'like', "%{$busca}%"));
            }));

        // NA FILA: tudo que ainda não foi liberado, até a data (arrasta de dias anteriores)
        $fila = (clone $base)
            ->whereNull('liberada_em')
            ->whereDate('created_at', '<=', $dataFiltro)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($n) => $n->paraTabela($dataFiltro));

        // Contadores antes dos filtros (mantêm o panorama do dia inteiro)
        $resumoAlertas = [
            Nota::NIVEL_CRITICO => $fila->where('nivel', Nota::NIVEL_CRITICO)->count(),
            Nota::NIVEL_ALERTA  => $fila->where('nivel', Nota::NIVEL_ALERTA)->count(),
            Nota::NIVEL_ATENCAO => $fila->where('nivel', Nota::NIVEL_ATENCAO)->count(),
        ];
        $totalReconferir = $fila->where('status', Nota::STATUS_RECONFERIR)->count();

        // Quantas notas da fila têm cada tipo de divergência em aberto
        $resumoTipos = collect(Card::TIPOS)
            ->mapWithKeys(fn($t) => [$t => $fila->filter(fn($n) => $this->temCardAtivo($n, $t))->count()])
            ->all();

        if ($nivel) {
            $fila = $fila->where('nivel', $nivel)->values();
        }
        if ($status) {
            $fila = $fila->where('status', $status)->values();
        }
        if ($tipo) {
            $fila = $fila->filter(fn($n) => $this->temCardAtivo($n, $tipo))->values();
        }

        // A separação que a antiga tela de Cadastros fazia, agora em seções:
        // recebimento (caminhão na porta, prioridade) × pré-lote (antecipadas)
        $recebimento = $fila->where('origem', 'recebimento')->values();
        $preLote     = $fila->where('origem', 'pre_lote')->values();

        // LIBERADAS: liberadas no dia exato, MAIS as já liberadas que o caminhão
        // trouxe hoje (recebida_em) — a nota fechada no pré-lote que chegou agora.
        $liberadas = (clone $base)
            ->whereNotNull('liberada_em')
            ->where(fn($q) => $q
                ->whereDate('liberada_em', $dataFiltro)
                ->orWhereDate('recebida_em', $dataFiltro))
            ->orderBy('liberada_em', 'desc')
            ->get()
            ->map(fn($n) => $n->paraTabela($dataFiltro));

        $fornecedores = Fornecedor::select('id', 'nome')->orderBy('nome')->get();

        return Inertia::render('Notas/Index', [
            'recebimento'   => $recebimento,
            'preLote'       => $preLote,
            'liberadas'     => $liberadas,
            'fornecedores'  => $fornecedores,
            'dataFiltro'      => $dataFiltro,
            'resumoAlertas'   => $resumoAlertas,
            'resumoTipos'     => $resumoTipos,
            'totalReconferir' => $totalReconferir,
            'filtros'       => [
                'busca'  => $busca,
                'loja'   => $loja,
                'nivel'  => $nivel,
                'status' => $status,
                'tipo'   => $tipo,
            ],
            'opcoes' => [
                'lojas'        => Nota::LOJAS,
                'origens'      => Nota::ORIGENS,
                'tipos'        => Card::TIPOS,
                'tiposCompras' => Card::TIPOS_COMPRAS,
                'sla'     => [
                    'atencao' => Nota::SLA_ATENCAO,
                    'alerta'  => Nota::SLA_ALERTA,
                    'critico' => Nota::SLA_CRITICO,
                ],
            ],
        ]);
    }

    /**
     * A nota tem divergência DESTE tipo ainda em aberto?
     *
     * O que importa para o filtro é o que ainda pede ação: card já resolvido é
     * histórico e não deve trazer a nota de volta para a lista de "custo".
     *
     * @param  array<string, mixed>  $nota  linha no formato de Nota::paraTabela()
     */
    private function temCardAtivo(array $nota, string $tipo): bool
    {
        return collect($nota['cards'])->contains(
            fn($c) => $c['tipo'] === $tipo && $c['status'] !== Card::STATUS_RESOLVIDO
        );
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('lancar-nota');

        $novo = $request->boolean('fornecedor_novo');
        if ($novo) {
            $request->merge(['fornecedor_id' => null]); // texto livre no lugar do id
        }

        $dados = $request->validate([
            'numero_nota'     => 'required|string|max:30',
            'fornecedor_id'   => [Rule::requiredIf(! $novo), 'nullable', 'exists:fornecedores,id'],
            'fornecedor_nome' => [Rule::requiredIf($novo), 'nullable', 'string', 'max:255'],
            'loja'            => ['required', 'integer', Rule::in(Nota::LOJAS)],
            'origem'          => ['required', Rule::in(Nota::ORIGENS)],
            'observacao'      => 'nullable|string|max:500',
        ]);

        $fornecedorId = $this->resolverFornecedorId($request);

        // Não pode haver notas duplicadas: uma NF é única por fornecedor
        // (número + fornecedor). Se já existe, tratamos em vez de criar de novo.
        $existente = Nota::where('numero_nota', $dados['numero_nota'])
            ->where('fornecedor_id', $fornecedorId)
            ->first();

        if ($existente) {
            return $this->tratarDuplicada($request, $existente, $dados);
        }

        $nota = Nota::create([
            'numero_nota'   => $dados['numero_nota'],
            'fornecedor_id' => $fornecedorId,
            'loja'          => $dados['loja'],
            'origem'        => $dados['origem'],
            'observacao'    => $dados['observacao'] ?? null,
            'user_id'       => $request->user()->id,
        ]);

        event(new NotaAtualizada($nota));

        return back()->with('sucesso', 'Nota lançada.');
    }

    /**
     * A nota que se tentou lançar já existe. Três caminhos, nunca duplicar:
     *
     *   1. Já liberada no pré-lote → registra a chegada de hoje (recebida_em) e
     *      ela passa a aparecer em "liberadas neste dia". Não reabre nada.
     *   2. Mesma fila → é a mesma nota já lançada ali. Bloqueia.
     *   3. Fila diferente e ainda não liberada → pede confirmação para MOVER de
     *      fila (o frontend pergunta; ao confirmar, reenvia com confirmar_mover).
     *      Ao mover, os cards/divergências vão junto — é a mesma nota.
     */
    private function tratarDuplicada(Request $request, Nota $existente, array $dados): RedirectResponse
    {
        if ($existente->liberada_em) {
            $existente->update(['recebida_em' => now()]);
            event(new NotaAtualizada($existente));

            return back()->with('sucesso', sprintf(
                'Esta nota já foi liberada no pré-lote em %s — marcada como recebida hoje.',
                $existente->liberada_em->format('d/m'),
            ));
        }

        if ($existente->origem === $dados['origem']) {
            throw ValidationException::withMessages([
                'numero_nota' => 'Esta nota já está lançada em ' . Nota::ORIGEM_LABEL[$existente->origem] . '.',
            ]);
        }

        // Fila diferente: só move mediante confirmação explícita do usuário
        if (! $request->boolean('confirmar_mover')) {
            throw ValidationException::withMessages([
                // O frontend usa a fila atual para montar a pergunta e reenviar
                'duplicada' => $existente->origem,
            ]);
        }

        $existente->update(['origem' => $dados['origem']]);
        event(new NotaAtualizada($existente));

        return back()->with('sucesso', 'Nota movida para ' . Nota::ORIGEM_LABEL[$dados['origem']] . '.');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function update(Request $request, Nota $nota): RedirectResponse
    {
        Gate::authorize('gerenciar-notas');

        $novo = $request->boolean('fornecedor_novo');
        if ($novo) {
            $request->merge(['fornecedor_id' => null]);
        }

        $dados = $request->validate([
            'numero_nota'     => 'sometimes|string|max:30',
            'fornecedor_id'   => ['sometimes', 'nullable', 'exists:fornecedores,id'],
            'fornecedor_nome' => [Rule::requiredIf($novo), 'nullable', 'string', 'max:255'],
            'loja'            => ['sometimes', 'integer', Rule::in(Nota::LOJAS)],
            'origem'          => ['sometimes', Rule::in(Nota::ORIGENS)],
            'observacao'      => 'nullable|string|max:500',
        ]);

        if ($novo) {
            $dados['fornecedor_id'] = $this->resolverFornecedorId($request);
        }
        unset($dados['fornecedor_nome']); // não é coluna da nota

        // Editar não pode criar duplicata: número + fornecedor tem de ser único
        $numero = $dados['numero_nota']   ?? $nota->numero_nota;
        $fornId = $dados['fornecedor_id'] ?? $nota->fornecedor_id;
        $colide = Nota::where('numero_nota', $numero)
            ->where('fornecedor_id', $fornId)
            ->whereKeyNot($nota->id)
            ->exists();

        if ($colide) {
            throw ValidationException::withMessages([
                'numero_nota' => 'Já existe outra nota com esse número e fornecedor.',
            ]);
        }

        $nota->update($dados);

        event(new NotaAtualizada($nota));

        return back()->with('sucesso', 'Nota atualizada.');
    }

    // ─── LIBERAR (o ✅ — ato explícito do pré-lote) ────────────────────────────

    public function liberar(Request $request, Nota $nota): RedirectResponse
    {
        Gate::authorize('liberar-nota');

        $nota->load('cards');

        if ($nota->liberada_em) {
            return back()->withErrors(['nota' => 'Esta nota já foi liberada.']);
        }

        if (! $nota->podeSerLiberada()) {
            return back()->withErrors(['nota' => 'A nota ainda tem divergência em aberto — resolva os cards antes de liberar.']);
        }

        $nota->update([
            'liberada_por' => $request->user()->id,
            'liberada_em'  => now(),
        ]);

        event(new NotaAtualizada($nota));
        Notificador::notaLiberada($nota, $request->user());

        return back()->with('sucesso', 'Nota liberada.');
    }

    // ─── DESTROY (soft delete) ────────────────────────────────────────────────

    public function destroy(Request $request, Nota $nota): RedirectResponse
    {
        Gate::authorize('gerenciar-notas');

        // Nota já liberada é histórico fechado — desfazer isso é ato de admin
        if ($nota->liberada_em) {
            Gate::authorize('excluir-nota-liberada');
        }

        Notificador::notaRemovida($nota);

        $nota->delete();

        event(new NotaAtualizada(removidaId: $nota->id));

        return back()->with('sucesso', 'Nota removida.');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /**
     * Id do fornecedor. Com "fornecedor_novo" marcado, cria pelo nome (ou
     * reaproveita um já existente, sem diferenciar maiúsculas) — assim o
     * recebimento cadastra na hora, sem reimportar planilha.
     */
    private function resolverFornecedorId(Request $request): int
    {
        if ($request->boolean('fornecedor_novo')) {
            $nome = mb_strtoupper(trim((string) $request->input('fornecedor_nome')));
            return Fornecedor::firstOrCreate(['nome' => $nome])->id;
        }

        return (int) $request->input('fornecedor_id');
    }
}
