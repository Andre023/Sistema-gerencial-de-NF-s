<?php

namespace App\Events;

use App\Models\Nota;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa os navegadores conectados que uma nota mudou.
 *
 * Carrega a própria nota formatada no payload, para o cliente atualizar só
 * aquela linha (em vez de todo mundo recarregar a fila inteira a cada mudança).
 */
class NotaAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public ?Nota $nota = null,
        public ?int $removidaId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notas')];
    }

    public function broadcastAs(): string
    {
        return 'NotaAtualizada';
    }

    public function broadcastWith(): array
    {
        if ($this->removidaId !== null) {
            return ['removida' => $this->removidaId];
        }

        // Recarrega a nota + relações para refletir a mudança recém-feita.
        // A montagem mora no model (Nota::paraTelaAgora) porque a resposta das
        // ações entrega esta MESMA linha — e duas cópias da lista de relações
        // sairiam de sincronia sem ninguém perceber.
        $nota = $this->nota?->paraTelaAgora();

        if (! $nota) {
            return []; // sem payload → o cliente faz um reload de segurança
        }

        return ['nota' => $nota];
    }
}
