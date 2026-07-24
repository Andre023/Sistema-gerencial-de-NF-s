<?php

namespace App\Events;

use App\Models\User;
use App\Services\Notificador;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * O sino de uma pessoa mudou. Diferente do NotaAtualizada (que vai para todo
 * mundo na fila), este é dirigido: cada usuário tem seu canal privado.
 *
 * Mandamos o estado inteiro do sino, não o "delta" — assim a tela nunca fica
 * dessincronizada por um evento perdido, e encerrar um aviso (o contador cai)
 * usa o mesmo caminho de criar um.
 */
class NotificacoesAtualizadas implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public User $user) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('usuario.' . $this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'NotificacoesAtualizadas';
    }

    public function broadcastWith(): array
    {
        return Notificador::paraUsuario($this->user);
    }
}
