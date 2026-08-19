<?php

namespace App\Console\Commands;

use App\Models\Conversa;
use App\Models\Mensagem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga as mensagens de chat que já fizeram Mensagem::DIAS_DE_VIDA dias.
 *
 * ── O prazo é DE CADA MENSAGEM ─────────────────────────────────────────────
 * Isto não zera conversa nenhuma. A pergunta que a faxina faz é feita a UMA
 * mensagem de cada vez: "você já fez 21 dias?". A que foi mandada hoje sai
 * daqui a três semanas; a de ontem sai amanhã-mais-vinte; a de agora há pouco
 * fica onde está.
 *
 * O efeito é uma janela que desliza: a conversa vai perdendo o rabo enquanto
 * ganha começo, e quem conversa todo dia sempre tem as três últimas semanas
 * inteiras na tela. Uma conversa ativa NUNCA amanhece vazia.
 *
 * O que seria errado — e não é o que acontece aqui — é zerar tudo de três em
 * três semanas: isso levaria junto a mensagem de dez minutos atrás só porque
 * o calendário virou.
 *
 * ── Por que existe ─────────────────────────────────────────────────────────
 * Para o chat parar de crescer para sempre. Numa VM de 1 GB, tabela que só
 * cresce é problema adiado: a paginação segura a tela, mas o índice, o backup
 * diário e o buffer pool do MySQL sentem o tamanho. Com a janela, o chat se
 * estabiliza sozinho num patamar em vez de virar bola de neve.
 *
 * ── O que vai junto ────────────────────────────────────────────────────────
 * As reações somem pelo cascade do banco (mensagem_reacoes.mensagem_id) — esta
 * faxina nem precisa saber que elas existem.
 *
 * O anexo quase sempre já saiu antes: o arquivo vive DIAS_NO_SERVIDOR (3 dias)
 * e é o `chat:limpar-anexos` que o leva. Mesmo assim conferimos e apagamos o
 * que ainda estiver no disco — restauração de backup, faxina que não rodou por
 * uns dias, ou um `--dias` menor na mão deixam exatamente esse caso.
 *
 * Roda de madrugada pelo agendador (routes/console.php). Na mão é seguro:
 *
 *     php artisan chat:limpar-mensagens --simular      # só mostra o que sairia
 *     php artisan chat:limpar-mensagens --dias=30      # simula outro prazo
 */
class LimparMensagensAntigas extends Command
{
    protected $signature = 'chat:limpar-mensagens
                            {--dias= : Sobrescreve o prazo padrão}
                            {--simular : Só mostra o que seria apagado}';

    protected $description = 'Apaga as mensagens de chat que passaram do prazo de vida';

    /**
     * Quantas mensagens são carregadas por vez.
     *
     * A faxina não pode trazer a tabela inteira para a memória: são 1 GB de RAM
     * divididos com o MySQL, e o dia em que houver 80 mil mensagens vencidas é
     * justamente o dia em que ninguém está olhando. Em lotes, o pico de memória
     * é o do lote, não o do estrago acumulado.
     */
    private const LOTE = 200;

    public function handle(): int
    {
        // `??` e não `?:` — com `?:`, o "0" da linha de comando é FALSO em PHP e
        // cairia calado no prazo padrão (mesma armadilha do chat:limpar-anexos).
        $opcao = $this->option('dias');
        $dias  = $opcao === null || $opcao === '' ? Mensagem::DIAS_DE_VIDA : (int) $opcao;

        // `<=` pelo mesmo motivo do comando irmão: com `--dias=0` o corte é
        // agora, e quem força a limpeza espera que ela leve tudo.
        $corte = now()->subDays($dias);

        $vencidas = fn() => Mensagem::where('created_at', '<=', $corte);

        $total = $vencidas()->count();

        if ($total === 0) {
            $this->info('Nada a limpar.');

            return self::SUCCESS;
        }

        /*
         * As conversas atingidas, guardadas ANTES de apagar.
         *
         * Depois do delete não há como saber quais eram — e é delas que o
         * `ultima_mensagem_em` precisa ser refeito no fim.
         */
        $conversas = $vencidas()->distinct()->pluck('conversa_id');

        if ($this->option('simular')) {
            $this->info("Seriam apagadas {$total} mensagens, de {$conversas->count()} conversas.");
            $this->line("Corte: mensagens de {$corte->format('d/m/Y H:i')} para trás ({$dias} dias).");

            return self::SUCCESS;
        }

        $disco    = Storage::disk(Mensagem::DISCO);
        $arquivos = 0;

        $vencidas()
            ->select(['id', 'anexo_caminho', 'anexo_removido_em'])
            ->chunkById(self::LOTE, function ($lote) use ($disco, &$arquivos) {
                foreach ($lote as $mensagem) {
                    // Anexo que a faxina dos arquivos ainda não levou. O delete
                    // não reclama de arquivo inexistente, então o caso do
                    // backup restaurado sem o storage junto não derruba nada.
                    if ($mensagem->anexo_caminho !== null && $mensagem->anexo_removido_em === null) {
                        $disco->delete($mensagem->anexo_caminho);
                        $arquivos++;
                    }
                }

                // Um DELETE por lote, e não um por linha: 200 mensagens saem
                // numa consulta. As reações vão juntas, pelo cascade.
                Mensagem::whereIn('id', $lote->pluck('id'))->delete();
            });

        $this->refazerUltimaMensagem($conversas);

        $recado = "Mensagens de chat apagadas após {$dias} dias: {$total}"
                . ($arquivos > 0 ? " ({$arquivos} anexos ainda no disco foram junto)" : '');

        Log::info($recado);
        $this->info($recado . '.');

        return self::SUCCESS;
    }

    /**
     * Recoloca `conversas.ultima_mensagem_em` no lugar depois da faxina.
     *
     * Sem isto a coluna ficaria apontando para uma mensagem que não existe
     * mais. Conversa que perdeu TODAS as mensagens volta a `null` — e passa a
     * se comportar como uma conversa que nunca aconteceu, que é exatamente o
     * que ela é agora. A linha da conversa continua no banco de propósito: é
     * ela que guarda até onde cada um leu.
     *
     * Uma consulta por conversa atingida (no máximo algumas dezenas, uma vez
     * por noite). Não vale a pena espremer em SQL só para economizar o que
     * ninguém está esperando.
     */
    private function refazerUltimaMensagem(\Illuminate\Support\Collection $conversas): void
    {
        foreach ($conversas as $conversaId) {
            Conversa::where('id', $conversaId)->update([
                'ultima_mensagem_em' => Mensagem::where('conversa_id', $conversaId)->max('created_at'),
            ]);
        }
    }
}
