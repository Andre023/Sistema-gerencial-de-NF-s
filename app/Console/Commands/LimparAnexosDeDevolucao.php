<?php

namespace App\Console\Commands;

use App\Models\Devolucao;
use App\Models\DevolucaoAnexo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga os arquivos dos cards de devolução já conferidos e vencidos.
 *
 * O prazo começa a contar na CONFERÊNCIA, não no lançamento: enquanto ninguém
 * conferiu, o print é a única coisa que permite conferir — apagá-lo por idade
 * transformaria o card num bilhete sem prova.
 *
 * Some o arquivo, não o card. O registro (nota, fornecedor, motivo, quem
 * autorizou) é leve e é o histórico de quem devolveu o quê; o print é o que
 * pesa no disco.
 *
 * Roda de madrugada pelo agendador (routes/console.php). Na mão:
 *
 *     php artisan devolucoes:limpar-anexos --dias=0 --simular
 */
class LimparAnexosDeDevolucao extends Command
{
    protected $signature = 'devolucoes:limpar-anexos
                            {--dias= : Sobrescreve o prazo padrão}
                            {--simular : Só mostra o que seria apagado}';

    protected $description = 'Remove os arquivos de devoluções conferidas há mais de uma semana';

    public function handle(): int
    {
        // `??` e não `?:` — com `?:`, o "0" da linha de comando é FALSO em PHP
        // e cairia calado no prazo padrão (a mesma armadilha do chat:limpar-anexos).
        $opcao = $this->option('dias');
        $dias  = $opcao === null || $opcao === '' ? Devolucao::DIAS_APOS_CONFERIR : (int) $opcao;

        $corte = now()->subDays($dias);

        $vencidas = Devolucao::whereNotNull('conferida_em')
            ->where('conferida_em', '<=', $corte)
            ->whereHas('anexos')
            ->with('anexos')
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('Nada a limpar.');

            return self::SUCCESS;
        }

        $arquivos = $vencidas->sum(fn(Devolucao $d) => $d->anexos->count());
        $bytes    = $vencidas->sum(fn(Devolucao $d) => $d->anexos->sum('tamanho'));

        if ($this->option('simular')) {
            $this->info("Seriam removidos {$arquivos} arquivos de {$vencidas->count()} cards (" . $this->emMB($bytes) . ').');

            return self::SUCCESS;
        }

        $disco = Storage::disk(DevolucaoAnexo::DISCO);

        foreach ($vencidas as $devolucao) {
            foreach ($devolucao->anexos as $anexo) {
                // O delete não reclama de arquivo que já sumiu — restauração de
                // backup sem o storage junto deixa exatamente esse caso, e ele
                // não pode derrubar a faxina do resto.
                $disco->delete($anexo->caminho);
                $anexo->delete();
            }
        }

        $espaco = $this->emMB($bytes);

        Log::info("Anexos de devolução removidos após {$dias} dias: {$arquivos} ({$espaco})");
        $this->info("Arquivos removidos: {$arquivos} de {$vencidas->count()} cards ({$espaco}).");

        return self::SUCCESS;
    }

    private function emMB(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
