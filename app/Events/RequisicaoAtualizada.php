<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// 2. Mudamos a interface aqui também
class RequisicaoAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct() {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('requisicoes'),
        ];
    }

    // 3. Adicionamos esta função para cravar o nome exato do evento
    public function broadcastAs(): string
    {
        return 'RequisicaoAtualizada';
    }
}
