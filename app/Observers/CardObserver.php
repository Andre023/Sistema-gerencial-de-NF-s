<?php

namespace App\Observers;

use App\Models\Card;
use App\Models\Ocorrencia;
use App\Services\Ocorrencias;

/**
 * O ciclo do card visto de fora: abrir, corrigir, resolver, reabrir, excluir.
 *
 * A EXCLUSÃO é a razão de este observer existir. Antes, apagar um card levava
 * junto os três eventos que a linha do tempo mostrava dele — a divergência
 * inteira sumia do histórico da nota e ninguém ficava sabendo que existiu.
 */
class CardObserver
{
    public function created(Card $card): void
    {
        Ocorrencias::registrar($card->nota_id, Ocorrencia::CARD_ABERTO, [
            'tipo'    => $card->tipo,
            'detalhe' => $card->detalhe,
        ]);
    }

    public function updated(Card $card): void
    {
        $mudou = $card->getChanges();

        /*
         * Reabrir é o único que se conhece pelo contador, e vem primeiro: ele
         * também devolve o status para "aberto", e sem esta ordem a reabertura
         * apareceria como mais um "aberto" solto, sem dizer que é a segunda vez.
         */
        if (array_key_exists('reaberturas', $mudou)) {
            Ocorrencias::registrar($card->nota_id, Ocorrencia::CARD_REABERTO, [
                'tipo'        => $card->tipo,
                'reaberturas' => $card->reaberturas,
            ]);

            return;
        }

        // Corrigir carimba os dois campos no mesmo instante (corrigir já
        // resolve). Uma linha só, e a que a equipe reconhece: "corrigiu".
        if (array_key_exists('corrigido_em', $mudou) && $card->corrigido_em) {
            Ocorrencias::registrar($card->nota_id, Ocorrencia::CARD_CORRIGIDO, [
                'tipo' => $card->tipo,
            ]);

            return;
        }

        if (array_key_exists('resolvido_em', $mudou) && $card->resolvido_em) {
            Ocorrencias::registrar($card->nota_id, Ocorrencia::CARD_RESOLVIDO, [
                'tipo' => $card->tipo,
            ]);
        }
    }

    public function deleted(Card $card): void
    {
        Ocorrencias::registrar($card->nota_id, Ocorrencia::CARD_EXCLUIDO, [
            'tipo'    => $card->tipo,
            'status'  => $card->status,
            // O detalhe vai junto porque some com o card: sem ele, a linha diria
            // que uma divergência foi apagada sem dizer qual era.
            'detalhe' => $card->detalhe,
        ]);
    }
}
