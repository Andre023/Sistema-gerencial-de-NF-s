<?php

namespace App\Events;

use App\Models\Mensagem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensagem nova numa conversa.
 *
 * Vai pelos canais `usuario.{id}` que JÁ EXISTEM — os dois lados assinam o
 * próprio canal desde que entram no sistema (é o mesmo do sino). Nada de um
 * canal por conversa: com 26 pessoas conversando, seriam centenas de
 * assinaturas paradas no Reverb, numa VM de 1 GB, para entregar o que dois
 * canais já entregam.
 *
 * SÍNCRONO (ShouldBroadcastNow), como o NotaAtualizada e ao contrário do sino:
 * mensagem que chega três segundos depois porque o worker estava ocupado não
 * parece "tempo real", parece defeito.
 *
 * Vai também para o próprio remetente, de propósito: quem está com o sistema
 * aberto em duas telas vê o que acabou de mandar nas duas. A tela ignora a
 * repetição pelo id da mensagem.
 */
class MensagemEnviada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param int[] $destinatarios ids de quem deve receber o evento */
    public function __construct(
        public Mensagem $mensagem,
        public array $destinatarios,
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
        return 'MensagemEnviada';
    }

    public function broadcastWith(): array
    {
        $this->mensagem->loadMissing('autor:id,name,avatar_tipo,avatar_valor');

        return [
            'conversa_id' => $this->mensagem->conversa_id,
            'mensagem'    => $this->mensagem->paraTela(),
            // A barra lateral monta a linha da conversa sem ir buscar nada:
            // quem falou, com que cara, e o que apareceu na prévia.
            'autor'       => [
                'id'     => $this->mensagem->autor?->id,
                'name'   => $this->mensagem->autor?->name,
                'avatar' => $this->mensagem->autor?->avatar,
            ],
        ];
    }
}
