<?php

namespace App\Services;

use App\Events\NotificacoesAtualizadas;
use App\Models\Card;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem é avisado do quê. O ciclo do card já sabe o destinatário de cada salto —
 * aqui só traduzimos isso em notificação:
 *
 *   recebimento lança    → pré-lote           (nota nova esperando análise)
 *   pré-lote abre card   → compras            (users com papel compras)
 *   compras corrige      → quem abriu o card  (cards.aberto_por)
 *   pré-lote reabre      → compras de novo
 *   pré-lote libera      → quem lançou a nota (notas.user_id)
 *
 * Três regras valem para todos os saltos:
 *
 *   1. Uma notificação viva por nota, não uma por card. Abrir a segunda
 *      divergência acumula na mesma linha ("CUSTO, CADASTRO") em vez de
 *      disparar um segundo aviso. Sem fila/worker no meio: o agrupamento
 *      acontece na escrita, então nada depende de job atrasado para chegar.
 *   2. Ninguém é avisado da própria ação.
 *   3. A notificação morre quando o motivo morre. Se três pessoas de compras
 *      foram avisadas e uma corrigiu, o aviso some para as outras duas.
 */
class Notificador
{
    // ─── SALTO 0: nota recém lançada → pré-lote ────────────────────────────────

    /**
     * O recebimento lançou uma nota (caminhão na porta): o pré-lote precisa
     * saber que há nota nova esperando análise.
     *
     * Quando quem lança já é do pré-lote (nota antecipada), em geral não
     * avisamos: o setor que analisa é o mesmo que acabou de cadastrar, e o
     * aviso seria só ruído para os colegas na mesma sala.
     *
     * A exceção mora no usuário. Em loja onde a mesma pessoa recebe o caminhão
     * E analisa a nota, a suposição acima é falsa — o pré-lote da central
     * precisa ver a nota, e o silêncio a escondia. Quem acumula as duas funções
     * é marcado em Usuários (ver User::lancamentoAvisaPreLote).
     */
    public static function notaLancada(Nota $nota, User $autor): void
    {
        if (! $autor->lancamentoAvisaPreLote()) {
            return;
        }

        $preLote = User::where('role', User::ROLE_PRE_LOTE)->get();

        self::entregar($preLote, $nota, Notificacao::TIPO_LANCADA, collect(), $autor);
    }

    // ─── SALTO 1: pré-lote abriu card → compras ────────────────────────────────

    public static function cardAberto(Nota $nota, User $autor): void
    {
        // O pré-lote já olhou a nota (abriu divergência): ela deixou de ser "nova"
        self::encerrar($nota, [Notificacao::TIPO_LANCADA]);

        self::sincronizarCompras($nota, $autor, Notificacao::TIPO_DIVERGENCIA);
        self::sincronizarDoca($nota, $autor);
    }

    // ─── SALTO 1b: pré-lote reconferiu e continua errado → compras de novo ─────

    public static function cardReaberto(Nota $nota, User $autor): void
    {
        // A reconferência aconteceu: o "compras corrigiu" já cumpriu seu papel
        self::encerrar($nota, [Notificacao::TIPO_CORRIGIDO]);

        self::sincronizarCompras($nota, $autor, Notificacao::TIPO_REABERTO);
        // Reabrir um card de doca é pendência nova para quem fecha esses cards
        self::sincronizarDoca($nota, $autor);
    }

    // ─── SALTO 2: compras corrigiu → quem abriu o card ─────────────────────────

    public static function cardCorrigido(Nota $nota, Card $card, User $autor): void
    {
        $nota->load('cards');

        // Some da fila de compras assim que não sobra nada para eles arrumarem
        self::sincronizarCompras($nota, $autor, Notificacao::TIPO_DIVERGENCIA, semNovoAviso: true);
        // Idem para a doca: recusa/devolução fechadas somem do sino dos dois setores
        self::sincronizarDoca($nota, $autor, semNovoAviso: true);

        $destinatarios = self::quemAbriu($card);

        $tipos = $nota->cards
            ->filter(fn($c) => $c->status === Card::STATUS_RESOLVIDO && $c->corrigido_em !== null)
            ->pluck('tipo');

        self::entregar($destinatarios, $nota, Notificacao::TIPO_CORRIGIDO, $tipos, $autor);
    }

    // ─── Pré-lote resolveu direto (card de regra) ou apagou um aberto por engano ─

    public static function cardResolvido(Nota $nota, User $autor): void
    {
        self::sincronizarCompras($nota, $autor, Notificacao::TIPO_DIVERGENCIA, semNovoAviso: true);
        self::sincronizarDoca($nota, $autor, semNovoAviso: true);
    }

    // ─── SALTO 3: nota liberada → quem lançou ──────────────────────────────────

    public static function notaLiberada(Nota $nota, User $autor): void
    {
        // Nota liberada é fim de linha: nada mais na nota pede ação de ninguém
        self::encerrar($nota, [
            Notificacao::TIPO_DIVERGENCIA,
            Notificacao::TIPO_REABERTO,
            Notificacao::TIPO_CORRIGIDO,
            Notificacao::TIPO_LANCADA,
            Notificacao::TIPO_DOCA,
        ]);

        $quemLancou = $nota->user_id ? User::find($nota->user_id) : null;

        // Nota antecipada é lançada pelo próprio pré-lote, que costuma ser quem
        // libera — mirar em quem lançou (e não no setor) cobre os dois casos.
        self::entregar(
            $quemLancou ? collect([$quemLancou]) : collect(),
            $nota,
            Notificacao::TIPO_LIBERADA,
            collect(),
            $autor,
        );
    }

    // ─── Nota excluída: nada nela pede ação (o soft delete não cascateia) ──────

    public static function notaRemovida(Nota $nota): void
    {
        self::encerrar($nota, Notificacao::TIPOS);
    }

    /** Cancelada pelo fornecedor: some da fila, então nada nela pede ação. */
    public static function notaCancelada(Nota $nota): void
    {
        self::encerrar($nota, Notificacao::TIPOS);
    }

    // ─── Leitura para a tela ───────────────────────────────────────────────────

    /** Payload do sino: contador de pendentes + as últimas da lista. */
    public static function paraUsuario(User $user): array
    {
        $itens = Notificacao::with(['nota:id,numero_nota,fornecedor_id,loja', 'nota.fornecedor:id,nome'])
            ->where('user_id', $user->id)
            ->viva()
            ->noSino()
            ->orderByDesc('updated_at')
            ->limit(Notificacao::LIMITE_LISTA)
            ->get();

        return [
            /*
             * O contador respeita a MESMA janela da lista.
             *
             * Antes ele contava todas as pendentes de sempre, e a lista mostrava
             * as 15 mais recentes: o sino marcava 87 e abria com 15 itens, sem
             * dizer onde estavam os outros 72. Um número que não leva a lugar
             * nenhum é pior do que número nenhum.
             */
            'pendentes' => Notificacao::where('user_id', $user->id)->pendentes()->noSino()->count(),
            'itens'     => $itens->map(fn($n) => $n->paraTela())->values()->all(),
            'ativas'    => (bool) $user->notificacoes_ativas,
        ];
    }

    // ─── Motor ─────────────────────────────────────────────────────────────────

    /**
     * Recalcula o aviso de compras a partir do estado atual da nota. Se não há
     * card aberto que seja de compras, encerra o que estiver vivo — é assim que
     * o aviso some da tela dos outros compradores quando um deles corrige.
     *
     * @param bool $semNovoAviso a ação foi de resolução — atualiza ou encerra o
     *                           que existe, mas não cria aviso nem pisca de novo
     */
    private static function sincronizarCompras(
        Nota $nota,
        User $autor,
        string $tipo,
        bool $semNovoAviso = false,
    ): void {
        $nota->load('cards');

        // Card de "regra" é o pré-lote que resolve — avisar compras dele só
        // ensina o comprador a ignorar o sino.
        $tipos = $nota->cards
            ->filter(fn($c) => $c->status === Card::STATUS_ABERTO)
            ->filter(fn($c) => in_array($c->tipo, Card::TIPOS_COMPRAS, true))
            ->pluck('tipo');

        if ($tipos->isEmpty()) {
            self::encerrar($nota, [Notificacao::TIPO_DIVERGENCIA, Notificacao::TIPO_REABERTO]);
            return;
        }

        // Sobrou divergência, mas uma foi embora: o aviso de quem ainda não leu
        // precisa parar de citar o que já foi corrigido.
        if ($semNovoAviso) {
            self::reduzirTipos($nota, $tipos, [Notificacao::TIPO_DIVERGENCIA, Notificacao::TIPO_REABERTO]);
            return;
        }

        $compras = User::where('role', User::ROLE_COMPRAS)->get();

        self::entregar($compras, $nota, $tipo, $tipos, $autor);
    }

    /**
     * Recalcula o aviso da DOCA a partir do estado atual da nota.
     *
     * Espelha o sincronizarCompras, para os cards que compras abre mas não
     * fecha: recusa e devolução. Quem resolve é quem está com a
     * mercadoria e o papel na mão — pré-lote e recebimento —, e são eles que
     * precisam saber.
     *
     * Antes desta função ninguém era avisado desses cards: o motor só olhava
     * para TIPOS_COMPRAS, e um card de recusa ficava aberto na tela esperando
     * alguém passar os olhos por acaso.
     *
     * @param bool $semNovoAviso a ação foi de resolução — atualiza ou encerra o
     *                           que existe, mas não cria aviso nem pisca de novo
     */
    private static function sincronizarDoca(Nota $nota, User $autor, bool $semNovoAviso = false): void
    {
        $nota->load('cards');

        $tipos = $nota->cards
            ->filter(fn($c) => $c->status === Card::STATUS_ABERTO)
            ->filter(fn($c) => in_array($c->tipo, Card::TIPOS_AVISAM_DOCA, true))
            ->pluck('tipo');

        if ($tipos->isEmpty()) {
            self::encerrar($nota, [Notificacao::TIPO_DOCA]);
            return;
        }

        // Sobrou card, mas um foi embora: o aviso de quem ainda não leu precisa
        // parar de citar o que já foi resolvido.
        if ($semNovoAviso) {
            self::reduzirTipos($nota, $tipos, [Notificacao::TIPO_DOCA]);
            return;
        }

        $daDoca = User::whereIn('role', [User::ROLE_PRE_LOTE, User::ROLE_RECEBIMENTO])->get();

        self::entregar($daDoca, $nota, Notificacao::TIPO_DOCA, $tipos, $autor);
    }

    /**
     * Reescreve a lista de tipos dos avisos vivos sem mexer no lida_em — perder
     * um item da lista não é notícia nova, não deve piscar.
     *
     * @param Collection<int,string> $tipos
     * @param array<int,string> $familia quais tipos de aviso reescrever
     */
    private static function reduzirTipos(Nota $nota, Collection $tipos, array $familia): void
    {
        $vivas = Notificacao::where('nota_id', $nota->id)
            ->whereIn('tipo', $familia)
            ->viva()
            ->get();

        foreach ($vivas as $aviso) {
            $aviso->update([
                'dados' => [...$aviso->dados, 'tipos' => $tipos->unique()->sort()->values()->all()],
            ]);
        }

        self::atualizarTelas($vivas->pluck('user_id')->all());
    }

    /**
     * Cria ou atualiza o aviso de cada destinatário. Atualizar em vez de criar é
     * o que mantém "uma notificação por nota"; zerar lida_em faz ela voltar a
     * pesar no sino quando entra divergência nova numa nota já avisada.
     *
     * @param Collection<int,User> $destinatarios
     * @param Collection<int,string> $tipos tipos de card citados no aviso
     */
    private static function entregar(
        Collection $destinatarios,
        Nota $nota,
        string $tipo,
        Collection $tipos,
        User $autor,
    ): void {
        $dados = [
            'tipos' => $tipos->unique()->sort()->values()->all(),
            'autor' => $autor->name,
        ];

        // Família "compras precisa agir": divergência e reabertura compartilham a
        // mesma linha, senão o comprador vê dois avisos da mesma nota.
        $familia = in_array($tipo, [Notificacao::TIPO_DIVERGENCIA, Notificacao::TIPO_REABERTO], true)
            ? [Notificacao::TIPO_DIVERGENCIA, Notificacao::TIPO_REABERTO]
            : [$tipo];

        $afetados = [];

        foreach ($destinatarios as $destinatario) {
            // Ninguém precisa de aviso do que acabou de fazer
            if ($destinatario->id === $autor->id) {
                continue;
            }

            if (! $destinatario->notificacoes_ativas) {
                continue;
            }

            $viva = Notificacao::where('user_id', $destinatario->id)
                ->where('nota_id', $nota->id)
                ->whereIn('tipo', $familia)
                ->viva()
                ->first();

            if ($viva) {
                $viva->update(['tipo' => $tipo, 'dados' => $dados, 'lida_em' => null]);
            } else {
                Notificacao::create([
                    'user_id' => $destinatario->id,
                    'nota_id' => $nota->id,
                    'tipo'    => $tipo,
                    'dados'   => $dados,
                ]);
            }

            $afetados[] = $destinatario->id;
        }

        self::atualizarTelas($afetados);
    }

    /** Marca como encerradas as vivas da nota nos tipos dados (o motivo sumiu). */
    private static function encerrar(Nota $nota, array $tipos): void
    {
        $alvos = Notificacao::where('nota_id', $nota->id)
            ->whereIn('tipo', $tipos)
            ->viva()
            ->get();

        if ($alvos->isEmpty()) {
            return;
        }

        Notificacao::whereIn('id', $alvos->pluck('id'))->update(['encerrada_em' => now()]);

        self::atualizarTelas($alvos->pluck('user_id')->all());
    }

    /**
     * Empurra o novo estado do sino para os navegadores dos envolvidos.
     *
     * Uma query só para todo mundo: antes era um User::find() DENTRO do laço,
     * então avisar as cinco pessoas de compras custava cinco viagens ao banco
     * — no meio do request de quem só queria abrir um card.
     */
    private static function atualizarTelas(array $userIds): void
    {
        $ids = array_unique($userIds);

        if (! $ids) {
            return;
        }

        foreach (User::whereIn('id', $ids)->get() as $user) {
            event(new NotificacoesAtualizadas($user));
        }
    }

    /**
     * Quem abriu o card. Se a conta foi removida (aberto_por vira null), o aviso
     * vai para o pré-lote inteiro em vez de se perder.
     *
     * @return Collection<int,User>
     */
    private static function quemAbriu(Card $card): Collection
    {
        if ($card->aberto_por) {
            $autor = User::find($card->aberto_por);

            if ($autor) {
                return collect([$autor]);
            }
        }

        return User::where('role', User::ROLE_PRE_LOTE)->get();
    }
}
