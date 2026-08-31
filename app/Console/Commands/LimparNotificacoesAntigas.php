<?php

namespace App\Console\Commands;

use App\Models\Notificacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apaga os avisos do sino que já cumpriram o papel.
 *
 * ── Por que existe ─────────────────────────────────────────────────────────
 * A tabela era a maior do banco: 40.032 linhas e 10,4 MB, quase quatro vezes a
 * de notas — e 36.711 delas já estavam encerradas, ou seja, invisíveis para
 * todo mundo. Nascem cerca de 1.150 por dia e nada as apagava; a mais antiga
 * era do dia em que o sistema entrou no ar.
 *
 * Isso ainda não doía, mas o `Notificador::paraUsuario()` roda em toda abertura
 * de tela e conta as pendentes por cima dessa pilha. Numa VM de 1 GB, tabela
 * que só cresce é problema adiado: o índice, o backup diário e o buffer pool do
 * MySQL sentem o tamanho muito antes da tela.
 *
 * ── O que sai, e o que NUNCA sai ───────────────────────────────────────────
 * Sai o que já não pesa no sino: lido ou encerrado, e mais velho que o prazo.
 *
 * NÃO sai o que continua PENDENTE, por mais velho que seja. Se ninguém leu e o
 * motivo segue de pé, aquele aviso ainda é a cobrança — apagá-lo por idade
 * seria resolver a pendência escondendo-a, que é o oposto do que o sino faz.
 *
 * ── E o histórico? ─────────────────────────────────────────────────────────
 * Não se perde nada. Quem guarda o que aconteceu com a nota é a tabela
 * `ocorrencias`, que só cresce e não é tocada aqui. Isto é a caixa de entrada,
 * não o arquivo.
 *
 * Roda de madrugada pelo agendador (routes/console.php). Na mão é seguro:
 *
 *     php artisan notificacoes:limpar --simular     # só mostra o que sairia
 *     php artisan notificacoes:limpar --dias=90     # simula outro prazo
 */
class LimparNotificacoesAntigas extends Command
{
    protected $signature = 'notificacoes:limpar
                            {--dias= : Sobrescreve o prazo padrão}
                            {--simular : Só mostra o que seria apagado}';

    protected $description = 'Apaga os avisos do sino já lidos ou encerrados que passaram do prazo';

    /**
     * Quantos avisos saem por vez.
     *
     * O primeiro dia em que isto rodar vai encontrar dezenas de milhares
     * vencidos de uma vez — exatamente o dia em que ninguém está olhando. Em
     * lotes, o pico é o do lote e não o do acumulado, e o DELETE não segura a
     * tabela por tempo suficiente para atrapalhar quem está lançando nota.
     */
    private const LOTE = 500;

    public function handle(): int
    {
        // `??` e não `?:` — com `?:`, o "0" da linha de comando é FALSO em PHP e
        // cairia calado no prazo padrão (mesma armadilha dos comandos irmãos).
        $opcao = $this->option('dias');
        $dias  = $opcao === null || $opcao === '' ? Notificacao::DIAS_DE_VIDA : (int) $opcao;

        $corte = now()->subDays($dias);

        /*
         * "Já cumpriu o papel" = saiu do estado pendente.
         *
         * O escopo `pendentes()` é `lida_em IS NULL AND encerrada_em IS NULL`;
         * o que procuramos aqui é o contrário dele — qualquer um dos dois
         * carimbos preenchido basta.
         */
        $vencidas = fn() => Notificacao::where('created_at', '<=', $corte)
            ->where(fn($q) => $q->whereNotNull('lida_em')->orWhereNotNull('encerrada_em'));

        $total = $vencidas()->count();

        if ($total === 0) {
            $this->info('Nada a limpar.');

            return self::SUCCESS;
        }

        if ($this->option('simular')) {
            // As que ficam por serem pendentes: é o número que mostra que a
            // faxina não está levando cobrança viva junto.
            $pendentesVelhas = Notificacao::where('created_at', '<=', $corte)->pendentes()->count();

            $this->info("Seriam apagados {$total} avisos já lidos ou encerrados.");
            $this->line("Corte: criados até {$corte->format('d/m/Y H:i')} ({$dias} dias).");
            $this->line("Continuariam onde estão: {$pendentesVelhas} avisos velhos ainda PENDENTES.");

            return self::SUCCESS;
        }

        $apagados = 0;

        do {
            // `limit` no DELETE em vez de carregar ids: são só linhas, sem
            // arquivo em disco para acompanhar como no chat, então não há
            // motivo para trazer nada para a memória.
            $saiu = $vencidas()->limit(self::LOTE)->delete();
            $apagados += $saiu;
        } while ($saiu > 0);

        $recado = "Avisos do sino apagados após {$dias} dias (lidos ou encerrados): {$apagados}";

        Log::info($recado);
        $this->info($recado . '.');

        return self::SUCCESS;
    }
}
