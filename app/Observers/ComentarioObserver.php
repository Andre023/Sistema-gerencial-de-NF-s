<?php

namespace App\Observers;

use App\Models\Comentario;
use App\Models\Nota;
use App\Models\Ocorrencia;
use App\Services\Ocorrencias;

/**
 * Comentário criado e apagado.
 *
 * O APAGADO é o caso que mais pedia isto. `Comentario` não usa SoftDeletes: a
 * linha sai do banco e não deixa rastro nenhum — e quem pode apagar é o autor
 * OU qualquer conta que gerencie notas. Ou seja, dava para tirar da tela o
 * recado incômodo de outra pessoa sem que ninguém notasse.
 *
 * Por isso o TEXTO vai junto na ocorrência. Registrar só "fulano apagou um
 * comentário" trocaria um buraco por outro: continuaria impossível saber o que
 * foi apagado, que costuma ser justamente o ponto da discussão.
 */
class ComentarioObserver
{
    public function created(Comentario $comentario): void
    {
        if (! $notaId = $this->notaDe($comentario)) {
            return;
        }

        Ocorrencias::registrar($notaId, Ocorrencia::COMENTARIO_CRIADO, [
            'texto' => $comentario->texto,
        ]);
    }

    public function deleted(Comentario $comentario): void
    {
        if (! $notaId = $this->notaDe($comentario)) {
            return;
        }

        Ocorrencias::registrar($notaId, Ocorrencia::COMENTARIO_EXCLUIDO, [
            'texto'  => $comentario->texto,
            // Quem escreveu, que não é necessariamente quem apagou — e é a
            // diferença entre alguém se retratar e alguém calar o outro.
            'autor'  => $comentario->user?->name,
        ]);
    }

    /**
     * Comentário é polimórfico (nota hoje, outros registros amanhã). O livro de
     * ocorrências é da NOTA, então o que não for nota passa direto.
     */
    private function notaDe(Comentario $comentario): ?int
    {
        return $comentario->comentavel_type === Nota::class
            ? (int) $comentario->comentavel_id
            : null;
    }
}
