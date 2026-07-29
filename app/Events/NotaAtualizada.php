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
        // Formatada relativa a "hoje" (correto para quem vê o dia atual — o caso comum).
        $nota = $this->nota?->fresh([
            'fornecedor:id,nome,prioridade',
            'user:id,name,avatar_tipo,avatar_valor',
            'liberadaPor:id,name,avatar_tipo,avatar_valor',
            'visualizadaPor:id,name,avatar_tipo,avatar_valor',
            'canceladaPor:id,name,avatar_tipo,avatar_valor',
            'cards',
        ]);

        if (! $nota) {
            return []; // sem payload → o cliente faz um reload de segurança
        }

        $nota->loadCount('comentarios');

        return ['nota' => $nota->paraTabela(now()->toDateString())];
    }
}
