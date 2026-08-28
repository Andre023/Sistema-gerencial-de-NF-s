<?php

namespace App\Observers;

use App\Models\Nota;
use App\Models\Ocorrencia;
use App\Services\Ocorrencias;

/**
 * O que acontece com a NOTA vira ocorrência aqui.
 *
 * Escutar o modelo, e não chamar o registro dentro de cada controller, é o que
 * faz o log não apodrecer: rota nova que edite a observação já nasce registrada.
 */
class NotaObserver
{
    /**
     * Nota nova.
     *
     * `created` e não uma chamada no NotaController::store porque a nota também
     * nasce em teste e em seeder — e um log que só conhece o caminho da tela
     * mente sobre a origem do resto.
     */
    public function created(Nota $nota): void
    {
        Ocorrencias::limparIntencao(); // criar não consome intenção de update
        Ocorrencias::registrar($nota->id, Ocorrencia::NOTA_LANCADA);
    }

    public function updated(Nota $nota): void
    {
        $intencao = Ocorrencias::consumirIntencao();
        $campos   = Ocorrencias::diff($nota->getChanges(), $nota->getOriginal());

        // Ato com nome próprio (liberou, cancelou, devolveu): o nome manda, e o
        // que mudou junto vai como detalhe.
        if ($intencao) {
            Ocorrencias::registrar($nota->id, $intencao['acao'], array_filter([
                'campos'   => $campos ?: null,
                'contexto' => $intencao['contexto'] ?: null,
            ]));

            return;
        }

        // Sem nome: é edição de campo, e o antes/depois já se explica sozinho.
        // Sem campo observado tocado, não houve nada que valha uma linha — é
        // aqui que o 🙋‍♂️ (visualizando_por) para de virar ocorrência.
        if (! $campos) {
            return;
        }

        Ocorrencias::registrar($nota->id, Ocorrencia::NOTA_EDITADA, ['campos' => $campos]);
    }

    /** Exclusão da nota — soft delete, mas a ocorrência fica de qualquer forma. */
    public function deleted(Nota $nota): void
    {
        Ocorrencias::limparIntencao();
        Ocorrencias::registrar($nota->id, Ocorrencia::NOTA_EXCLUIDA);
    }
}
