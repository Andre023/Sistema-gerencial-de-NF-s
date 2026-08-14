<?php

namespace App\Console\Commands;

use App\Models\Mensagem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tira do disco os anexos de chat que passaram do prazo.
 *
 * É o que faz o chat não virar depósito: foto de galpão e PDF de fornecedor
 * ficam Mensagem::DIAS_NO_SERVIDOR dias e saem. A mensagem continua na
 * conversa — some o arquivo, não o registro.
 *
 * Isso não é perda de conteúdo porque cada navegador guarda a própria cópia do
 * que exibiu. Quem abriu a foto dentro do prazo continua vendo depois; quem
 * nunca abriu, não. O prazo existe justamente para dar tempo de abrir.
 *
 * Roda de madrugada pelo agendador (routes/console.php). Rodar na mão é
 * seguro a qualquer hora:
 *
 *     php artisan chat:limpar-anexos --dias=7   # simula outro prazo
 */
class LimparAnexosDeChat extends Command
{
    protected $signature = 'chat:limpar-anexos
                            {--dias= : Sobrescreve o prazo padrão}
                            {--simular : Só mostra o que seria apagado}';

    protected $description = 'Remove do disco os anexos de chat que passaram do prazo';

    public function handle(): int
    {
        // `?? ` e não `?:` — com `?:`, o "0" da linha de comando é FALSO em PHP
        // e cairia calado no prazo padrão. Quem digita `--dias=0` para forçar a
        // limpeza veria "nada a limpar" com os arquivos ali, parados.
        $opcao = $this->option('dias');
        $dias  = $opcao === null || $opcao === '' ? Mensagem::DIAS_NO_SERVIDOR : (int) $opcao;

        $corte = now()->subDays($dias);

        // `<=` e não `<`: com `--dias=0` o corte é o instante de agora, e a
        // mensagem enviada neste mesmo segundo ficaria de fora do "menor que".
        // Quem força a limpeza espera que ela leve tudo.
        $vencidas = Mensagem::whereNotNull('anexo_caminho')
            ->whereNull('anexo_removido_em')
            ->where('created_at', '<=', $corte)
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('Nada a limpar.');

            return self::SUCCESS;
        }

        $bytes = $vencidas->sum('anexo_tamanho');

        if ($this->option('simular')) {
            $this->info("Seriam removidos {$vencidas->count()} arquivos (" . $this->emMB($bytes) . ').');

            return self::SUCCESS;
        }

        $disco = Storage::disk(Mensagem::DISCO);

        foreach ($vencidas as $mensagem) {
            // O delete não reclama de arquivo que já não existe — restauração de
            // backup sem o storage junto deixa exatamente esse caso, e ele não
            // pode derrubar a faxina do resto.
            $disco->delete($mensagem->anexo_caminho);

            $mensagem->update(['anexo_removido_em' => now()]);
        }

        $quantos = $vencidas->count();
        $espaco  = $this->emMB($bytes);

        Log::info("Anexos de chat removidos após {$dias} dias: {$quantos} ({$espaco})");
        $this->info("Anexos removidos: {$quantos} ({$espaco}).");

        return self::SUCCESS;
    }

    private function emMB(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
