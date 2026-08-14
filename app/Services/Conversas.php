<?php

namespace App\Services;

use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O que a barra lateral precisa saber para desenhar a lista de pessoas.
 *
 * Todo mundo conversa com todo mundo: a lista é o quadro de usuários inteiro,
 * não só quem já trocou mensagem. Quem nunca conversou aparece do mesmo jeito,
 * sem conversa nenhuma criada no banco — a conversa nasce na primeira mensagem.
 *
 * A regra que orienta as consultas daqui: **nada por pessoa**. São 26 contas;
 * um contador de não lidas por conta seriam 26 idas ao banco a cada abertura da
 * barra. Tudo aqui é resolvido em consultas agregadas, de número fixo.
 */
class Conversas
{
    /** Quantos caracteres da última mensagem aparecem na prévia da lista. */
    private const PREVIA = 60;

    /**
     * A lista completa: cada pessoa, o estado da conversa com ela e o que ficou
     * por ler.
     */
    public static function paraUsuario(User $user): array
    {
        $pessoas = User::where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'avatar_tipo', 'avatar_valor']);

        // Uma consulta para todas as conversas diretas desta pessoa, indexadas
        // pela chave "menorId-maiorId" — a mesma que o model monta.
        $chaves = $pessoas->map(fn(User $p) => Conversa::chaveDireta($user->id, $p->id));

        $conversas = Conversa::whereIn('chave_direta', $chaves)
            ->get(['id', 'chave_direta', 'ultima_mensagem_em'])
            ->keyBy('chave_direta');

        $ultimas  = self::ultimaMensagemDe($conversas->pluck('id')->all());
        $naoLidas = self::naoLidasPorConversa($user);

        $lista = $pessoas->map(function (User $pessoa) use ($user, $conversas, $ultimas, $naoLidas) {
            $conversa = $conversas->get(Conversa::chaveDireta($user->id, $pessoa->id));
            $ultima   = $conversa ? $ultimas->get($conversa->id) : null;

            return [
                'id'     => $pessoa->id,
                'nome'   => $pessoa->name,
                'papel'  => $pessoa->role,
                'avatar' => $pessoa->avatar,

                'conversa_id' => $conversa?->id,
                'nao_lidas'   => $conversa ? (int) ($naoLidas[$conversa->id] ?? 0) : 0,

                'ultima' => $ultima ? [
                    'previa' => self::previa($ultima),
                    'em'     => $ultima->created_at,
                    'minha'  => $ultima->user_id === $user->id,
                ] : null,
            ];
        });

        return [
            'pessoas'   => $lista->values()->all(),
            'nao_lidas' => (int) array_sum($naoLidas),
        ];
    }

    /**
     * Só o total, para o balãozinho da barra recolhida.
     *
     * Separado da lista de propósito: este roda em TODA navegação (vai nas props
     * compartilhadas do Inertia), então precisa ser uma consulta só e barata.
     * A lista inteira só é montada quando a pessoa abre a barra.
     */
    public static function naoLidasDe(User $user): int
    {
        return self::baseNaoLidas($user)->count();
    }

    /**
     * Quem está devendo resposta — só as pessoas com mensagem por ler, da mais
     * recente para a mais antiga.
     *
     * É o que faz o rosto de quem mandou mensagem subir ao topo da barra
     * recolhida ASSIM QUE A PÁGINA ABRE. A lista completa (paraUsuario) só é
     * buscada quando alguém expande a barra, e até lá não havia como saber de
     * quem era o número aceso no balãozinho.
     *
     * Vai nas props compartilhadas, ou seja: roda em toda navegação. Por isso
     * traz só quem tem pendência — quase sempre ninguém, e aí a segunda
     * consulta nem acontece.
     */
    public static function pendentesDe(User $user): array
    {
        $porConversa = self::baseNaoLidas($user)
            ->groupBy('m.conversa_id')
            ->selectRaw('m.conversa_id, COUNT(*) as total, MAX(m.created_at) as em')
            ->get();

        if ($porConversa->isEmpty()) {
            return [];
        }

        // O outro lado de cada conversa, numa consulta só
        $outros = DB::table('conversa_participantes as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.conversa_id', $porConversa->pluck('conversa_id'))
            ->where('p.user_id', '!=', $user->id)
            ->select('p.conversa_id', 'u.id', 'u.name', 'u.avatar_tipo', 'u.avatar_valor')
            ->get()
            ->keyBy('conversa_id');

        return $porConversa
            ->map(function ($linha) use ($outros) {
                $outro = $outros->get($linha->conversa_id);

                // Conta removida no meio do caminho: sem rosto para mostrar
                if (! $outro) {
                    return null;
                }

                return [
                    'id'        => (int) $outro->id,
                    'nome'      => $outro->name,
                    // Mesmo formato do getAvatarAttribute do User, para o
                    // componente <Avatar> não precisar saber de onde veio.
                    'avatar'    => [
                        'tipo'  => $outro->avatar_tipo ?? 'monograma',
                        'valor' => $outro->avatar_valor,
                    ],
                    'nao_lidas' => (int) $linha->total,
                    'em'        => $linha->em,
                ];
            })
            ->filter()
            ->sortByDesc('em')
            ->values()
            ->all();
    }

    // ─── Consultas ──────────────────────────────────────────────────────────────

    /**
     * As não lidas de cada conversa, em uma consulta.
     *
     * "Não lida" é comparação de inteiros: mensagem com id acima do ponteiro de
     * leitura da pessoa (conversa_participantes.lida_ate_id). O COALESCE cobre
     * quem nunca leu nada — ponteiro nulo vale zero, então tudo conta.
     *
     * @return array<int,int> conversa_id => quantidade
     */
    private static function naoLidasPorConversa(User $user): array
    {
        return self::baseNaoLidas($user)
            ->groupBy('m.conversa_id')
            ->selectRaw('m.conversa_id, COUNT(*) as total')
            ->pluck('total', 'm.conversa_id')
            ->map(fn($n) => (int) $n)
            ->all();
    }

    /**
     * O tronco comum das duas consultas de não lidas.
     *
     * O `user_id IS NULL` no meio não é preciosismo: quando uma conta é removida
     * a mensagem fica com autor nulo, e em SQL `NULL != 5` não é verdadeiro — é
     * nulo. Sem essa linha, mensagem de quem saiu da empresa desapareceria da
     * contagem e o balãozinho mostraria menos do que há para ler.
     */
    private static function baseNaoLidas(User $user)
    {
        return DB::table('mensagens as m')
            ->join('conversa_participantes as p', 'p.conversa_id', '=', 'm.conversa_id')
            ->where('p.user_id', $user->id)
            ->where(fn($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->whereRaw('m.id > COALESCE(p.lida_ate_id, 0)');
    }

    /**
     * A última mensagem de cada conversa.
     *
     * Duas consultas em vez de uma por conversa: primeiro os ids (MAX agrupado),
     * depois as linhas correspondentes.
     *
     * @param int[] $conversaIds
     */
    private static function ultimaMensagemDe(array $conversaIds)
    {
        if (! $conversaIds) {
            return collect();
        }

        $ids = Mensagem::whereIn('conversa_id', $conversaIds)
            ->groupBy('conversa_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        return Mensagem::whereIn('id', $ids)->get()->keyBy('conversa_id');
    }

    /** O que a lista mostra embaixo do nome. Arquivo sem legenda vira rótulo. */
    private static function previa(Mensagem $m): string
    {
        if (filled($m->texto)) {
            return mb_strimwidth(trim($m->texto), 0, self::PREVIA, '…');
        }

        if ($m->temAnexo()) {
            return $m->ehImagem() ? 'Foto' : ($m->anexo_nome ?? 'Arquivo');
        }

        return '';
    }
}
