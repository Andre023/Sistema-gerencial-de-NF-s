<?php

namespace App\Events;

use App\Models\Devolucao;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * O quadro de devoluções mudou — avisa quem está com a tela aberta.
 *
 * Mesmo desenho do NotaAtualizada: carrega o card já formatado, para o cliente
 * atualizar só aquele cartão em vez de todo mundo recarregar o quadro inteiro.
 * Síncrono pelo mesmo motivo — é o que move o cartão na tela dos outros, e ali
 * o atraso apareceria.
 */
class DevolucaoAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public ?Devolucao $devolucao = null,
        public ?int $removidaId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('devolucoes')];
    }

    public function broadcastAs(): string
    {
        return 'DevolucaoAtualizada';
    }

    public function broadcastWith(): array
    {
        if ($this->removidaId !== null) {
            return ['removida' => $this->removidaId];
        }

        $card = $this->devolucao?->fresh(['anexos', 'criadaPor:id,name', 'conferidaPor:id,name']);

        return $card ? ['devolucao' => $card->paraQuadro()] : [];
    }
}
