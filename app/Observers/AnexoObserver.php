<?php

namespace App\Observers;

use App\Models\Anexo;
use App\Models\Ocorrencia;
use App\Services\Ocorrencias;

/**
 * Documentos e fotos entrando e saindo da nota.
 *
 * O anexo tem vida curta por desenho (o job apaga os da nota liberada depois de
 * dois dias), então a pergunta "cadê a foto da avaria?" ia ficar sem resposta:
 * some do mesmo jeito quem apagou por engano e quem apagou porque venceu o
 * prazo. A ocorrência separa os dois — o job roda sem ninguém logado e a linha
 * dele sai assinada como "Sistema".
 */
class AnexoObserver
{
    public function created(Anexo $anexo): void
    {
        Ocorrencias::registrar($anexo->nota_id, Ocorrencia::ANEXO_ENVIADO, [
            'nome' => $anexo->nome_original,
        ]);
    }

    public function deleted(Anexo $anexo): void
    {
        Ocorrencias::registrar($anexo->nota_id, Ocorrencia::ANEXO_REMOVIDO, [
            'nome' => $anexo->nome_original,
        ]);
    }
}
