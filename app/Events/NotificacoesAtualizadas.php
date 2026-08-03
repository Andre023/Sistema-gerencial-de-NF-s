<?php

namespace App\Events;

use App\Models\User;
use App\Services\Notificador;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O sino de uma pessoa mudou. Diferente do NotaAtualizada (que vai para todo
 * mundo na fila), este é dirigido: cada usuário tem seu canal privado.
 *
 * Mandamos o estado inteiro do sino, não o "delta" — assim a tela nunca fica
 * dessincronizada por um evento perdido, e encerrar um aviso (o contador cai)
 * usa o mesmo caminho de criar um.
 *
 * VAI PELA FILA (ShouldBroadcast, não ...Now). Abrir um card avisa todo o setor
 * de compras, e cada aviso custa duas queries + uma chamada HTTP ao Reverb — com
 * o envio síncrono, quem clicou esperava por tudo isso antes de a tela responder.
 * Na fila, o request devolve na hora e o worker entrega os avisos logo atrás.
 *
 * O preço é uma dependência real: sem o worker `nfs-queue` de pé, o sino para de
 * atualizar sozinho (ver DEPLOY.md). O NotaAtualizada continua síncrono de
 * propósito — é ele que move a linha na tela de todo mundo, e ali o atraso
 * apareceria.
 */
class NotificacoesAtualizadas implements ShouldBroadcast
{
    // SerializesModels guarda só o id do usuário no job; o worker recarrega o
    // model na hora de montar o payload — que é justamente o estado mais novo.
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
