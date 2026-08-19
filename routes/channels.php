<?php

use App\Models\Conversa;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('presenca.sistema', function (User $user) {
    // Retornamos os dados que queremos que os outros vejam (inclui o avatar,
    // para a barra lateral mostrar a identidade de cada um).
    return [
        'id'     => $user->id,
        'name'   => $user->name,
        'avatar' => $user->avatar,
    ];
});

Broadcast::channel('notas', function ($user) {
    return true;
});

// Quadro de devoluções: quem enxerga o quadro acompanha o que muda nele.
Broadcast::channel('devolucoes', function (User $user) {
    return $user->podeUsarDevolucoes();
});

// Sino: cada um só escuta o próprio canal
Broadcast::channel('usuario.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/*
 * O canal da conversa aberta — existe SÓ para o "digitando…".
 *
 * ── Por que um canal novo, se tudo no chat passa por `usuario.{id}` ────────
 * Porque `usuario.{id}` tem UM assinante: o dono. É o que a autorização acima
 * garante. Um whisper ali não chegaria a ninguém — o Reverb retransmite para
 * os OUTROS assinantes do canal, e não há outros.
 *
 * O "digitando" precisa de um canal onde os dois lados estejam juntos, e é só
 * esse o motivo deste aqui existir.
 *
 * ── Isto não desfaz a decisão de não ter canal por conversa ────────────────
 * O ChatProvider assina este canal só ENQUANTO a conversa está aberta na tela,
 * e sai dele ao fechar. Cada pessoa tem no máximo uma conversa aberta por vez,
 * então o teto são 26 assinaturas — não as centenas paradas que a decisão
 * original evitou (que vinham de assinar TODAS as conversas o tempo todo).
 *
 * ── Nenhum evento do servidor passa por aqui ───────────────────────────────
 * Mensagem, leitura e reação continuam indo por `usuario.{id}`. Este canal só
 * carrega evento de CLIENTE (`client-digitando`), que o Reverb retransmite
 * sozinho, sem acordar o PHP nem o MySQL. É por isso que o "digitando" não
 * custa nada: ele nunca chega ao servidor de aplicação.
 *
 * O `accept_client_events_from` do Reverb está em 'members' (config/reverb.php),
 * o que quer dizer que ele só aceita whisper de quem passou por esta função.
 */
Broadcast::channel('conversa.{conversa}', function (User $user, int $conversa) {
    // find e não findOrFail: conversa que não existe é "não autorizado", e não
    // um erro 500 no meio de uma inscrição de WebSocket.
    return Conversa::find($conversa)?->temParticipante($user) ?? false;
});
