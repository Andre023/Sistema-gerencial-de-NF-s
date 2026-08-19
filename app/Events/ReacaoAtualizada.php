<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mudaram as reações de uma mensagem.
 *
 * Vai pelos mesmos canais `usuario.{id}` do MensagemEnviada — nada de canal
 * novo. Quem já está no sistema já escuta o próprio canal desde que entrou.
 *
 * ── Manda a lista INTEIRA, não o que mudou ────────────────────────────────
 * Seria menor mandar "fulano pôs 👍". Mas aí a tela teria de aplicar somas e
 * subtrações em cima do que ela acha que já tem — e um evento perdido no wi-fi
 * do galpão deixaria o contador errado para sempre, sem nada que o corrigisse.
 *
 * Mandando a lista pronta, cada evento reescreve o estado daquela mensagem.
 * Evento perdido só custa um piscar de olhos, e o próximo conserta. A lista é
 * minúscula (seis emojis possíveis, 26 pessoas), então o que se paga em bytes
 * volta em não ter de reconciliar nada.
 *
 * SÍNCRONO como os outros dois eventos do chat: reação que aparece três
 * segundos depois não parece tempo real, parece defeito.
 */
class ReacaoAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param int[] $destinatarios ids de quem deve receber o evento
     * @param array<int,array{emoji:string,user_id:int}> $reacoes como ficou a mensagem
     */
    public function __construct(
        public array $destinatarios,
        public int $conversaId,
        public int $mensagemId,
        public array $reacoes,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(
            fn(int $id) => new PrivateChannel('usuario.' . $id),
            $this->destinatarios,
        );
    }

    public function broadcastAs(): string
    {
        return 'ReacaoAtualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'conversa_id' => $this->conversaId,
            'mensagem_id' => $this->mensagemId,
            'reacoes'     => $this->reacoes,
        ];
    }
}
