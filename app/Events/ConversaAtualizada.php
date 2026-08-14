<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mudou algo na conversa que não é mensagem nova.
 *
 * Hoje há um aviso só, dirigido a quem MANDOU: 'lida' — o outro leu até a
 * mensagem N, que é o que acende o ✓✓.
 *
 * Payload minúsculo de propósito — é o evento que dispara com mais frequência
 * (toda abertura de conversa marca leitura).
 */
class ConversaAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const LIDA = 'lida';

    public function __construct(
        public int $destinatarioId,
        public int $conversaId,
        public string $o_que,
        /** id da última mensagem lida */
        public int $mensagemId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('usuario.' . $this->destinatarioId)];
    }

    public function broadcastAs(): string
    {
        return 'ConversaAtualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'conversa_id' => $this->conversaId,
            'o_que'       => $this->o_que,
            'mensagem_id' => $this->mensagemId,
        ];
    }
}
