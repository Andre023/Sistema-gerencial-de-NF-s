<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa todos os navegadores conectados que alguma nota mudou —
 * é também a "notificação" do fluxo: quando compras marca um card como
 * corrigido, a tela do pré-lote atualiza na hora.
 */
class NotaAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct() {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notas'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'NotaAtualizada';
    }
}
