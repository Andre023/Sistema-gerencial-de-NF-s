<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Uma folha da cotação mudou — avisa os outros compradores com ela aberta.
 *
 * Leva as operações (a mesma lista que a tela de quem editou mandou), e não a
 * folha: cada tela aplica em cima do que já tem, na ordem da `versao`. Folha
 * gerada de novo, importada ou alteração grande demais para o WebSocket vai
 * como `recarregar`, e quem recebe busca a folha inteira.
 *
 * `cliente` é a aba do navegador que fez a alteração: ela já aplicou na hora e
 * ignora o próprio eco.
 *
 * Síncrono como o DevolucaoAtualizada: é o que faz a célula mudar na tela do
 * colega enquanto ele olha, e fila ali apareceria como atraso.
 */
class CotacaoAlterada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** Acima disto vai `recarregar` — o Reverb não é lugar de folha inteira. */
    private const LIMITE_DO_AVISO = 8000;

    public function __construct(
        public int $cotacaoId,
        public int $folhaId,
        public int $versao,
        public string $cliente = '',
        public ?array $ops = null,
        public bool $removida = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('cotacao.' . $this->cotacaoId)];
    }

    public function broadcastAs(): string
    {
        return 'CotacaoAlterada';
    }

    public function broadcastWith(): array
    {
        $aviso = [
            'folha'   => $this->folhaId,
            'versao'  => $this->versao,
            'cliente' => $this->cliente,
        ];

        if ($this->removida) {
            return $aviso + ['removida' => true];
        }

        if ($this->ops !== null && strlen((string) json_encode($this->ops)) <= self::LIMITE_DO_AVISO) {
            return $aviso + ['ops' => $this->ops];
        }

        return $aviso + ['recarregar' => true];
    }
}
