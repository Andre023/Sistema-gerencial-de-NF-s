<?php

namespace App\Jobs;

use App\Models\Nota;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Apaga os anexos da nota 2 dias depois de ela ser liberada.
 *
 * Por que job com atraso e não tarefa agendada: o `schedule:run` do Laravel
 * exigiria uma entrada em cron que esta VM não tem, e viveria varrendo o banco
 * atrás do que apagar. Aqui o relógio começa no evento que importa — a própria
 * liberação agenda a limpeza dela — e quem executa é o worker (nfs-queue) que
 * já roda como serviço. Zero infraestrutura nova.
 *
 * Cancelamento e exclusão da nota NÃO passam por aqui: nesses casos o arquivo
 * sai na hora (NotaController), porque não há nada para esperar.
 */
class LimparAnexosDaNota implements ShouldQueue
{
    use Queueable;

    /** Quantos dias depois da liberação o arquivo deixa de ser necessário. */
    public const DIAS = 2;

    public function __construct(public int $notaId)
    {
    }

    public function handle(): void
    {
        // withTrashed: nota excluída no meio do caminho ainda tem arquivo em
        // disco para limpar. O soft delete some com a linha, não com o arquivo.
        $nota = Nota::withTrashed()->find($this->notaId);

        if (! $nota) {
            return; // apagada de vez — o cascade da FK já levou os registros
        }

        // A RECONFERÊNCIA. Entre o agendamento e agora a nota pode ter sido
        // devolvida (liberada_em volta a ser nulo) e estar de novo na fila,
        // precisando das fotos. Se foi liberada outra vez, existe um job novo
        // com a data nova — este aqui simplesmente não faz nada.
        if (! $nota->liberada_em || $nota->liberada_em->gt(now()->subDays(self::DIAS))) {
            return;
        }

        $quantos = $nota->apagarAnexos();

        if ($quantos > 0) {
            Log::info("Anexos removidos da nota {$nota->id}: {$quantos}");
        }
    }
}
