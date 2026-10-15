<?php

namespace App\Services;

use App\Events\MensagemEnviada;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * As novidades do sistema, contadas pelo Hipermon no chat.
 *
 * Por que no chat, e não numa tela de avisos à parte: o chat já é onde as
 * pessoas olham — tem o balãozinho de não lida, o rosto de quem mandou sobe
 * ao topo da barra e chega na hora pelo Reverb. Uma tela nova de "novidades"
 * seria mais um lugar que ninguém lembra de abrir.
 *
 * Publicar é mandar a MESMA mensagem, do Hipermon, na conversa direta dele
 * com cada conta. Uma conversa por pessoa, e não um "grupo": o chat só tem
 * conversa direta, e assim o não lido, o ✓✓ e a faxina de mensagens antigas
 * funcionam sem nenhuma regra especial.
 */
class Novidades
{
    /** Quantas publicações a tela de Configurações mostra como histórico. */
    private const HISTORICO = 10;

    /**
     * Manda a novidade para todo mundo. Devolve para quantas pessoas foi.
     *
     * As mensagens são gravadas numa transação só: ou todo mundo recebe, ou
     * ninguém — uma novidade que chegou para metade da equipe é pior que
     * nenhuma. O aviso em tempo real vai depois do commit e, se o Reverb
     * estiver fora, a mensagem já está gravada e aparece na próxima abertura.
     */
    public static function publicar(string $texto): int
    {
        $hipermon = User::hipermon()
            ?? throw new RuntimeException('A conta do Hipermon não existe — rode as migrations.');

        $pessoas = User::where('role', '!=', User::ROLE_SISTEMA)->get();

        $enviadas = DB::transaction(function () use ($hipermon, $pessoas, $texto) {
            return $pessoas->map(function (User $pessoa) use ($hipermon, $texto) {
                $conversa = Conversa::entre($hipermon, $pessoa);

                $mensagem = $conversa->mensagens()->create([
                    'user_id' => $hipermon->id,
                    'texto'   => $texto,
                ]);

                $conversa->update(['ultima_mensagem_em' => $mensagem->created_at]);
                $conversa->marcarLida($hipermon, $mensagem->id);

                return [$mensagem, [$hipermon->id, $pessoa->id]];
            });
        });

        foreach ($enviadas as [$mensagem, $participantes]) {
            try {
                event(new MensagemEnviada($mensagem, $participantes));
            } catch (Throwable $e) {
                // Sem tempo real não é sem mensagem: ela já está no banco.
                report($e);
            }
        }

        return $enviadas->count();
    }

    /**
     * As últimas publicações, da mais nova para a mais velha.
     *
     * Não há tabela de "novidades": a publicação é o conjunto das mensagens
     * iguais que o Hipermon mandou no mesmo instante. Agrupar pelo texto basta
     * — duas novidades com o mesmo texto seriam a mesma novidade.
     *
     * Elas somem daqui junto com as mensagens, na faxina do chat
     * (Mensagem::DIAS_DE_VIDA).
     */
    public static function historico(): array
    {
        $hipermon = User::hipermon();

        if (! $hipermon) {
            return [];
        }

        return Mensagem::where('user_id', $hipermon->id)
            ->whereNotNull('texto')
            ->groupBy('texto')
            ->selectRaw('texto, MAX(created_at) as em, COUNT(*) as pessoas')
            ->orderByDesc('em')
            ->limit(self::HISTORICO)
            ->get()
            ->map(fn($m) => [
                'texto'   => $m->texto,
                'em'      => $m->em,
                'pessoas' => (int) $m->pessoas,
            ])
            ->all();
    }
}
