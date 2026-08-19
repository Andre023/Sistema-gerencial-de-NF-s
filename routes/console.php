<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Rotinas agendadas ────────────────────────────────────────────────────────
//
// O cron do servidor chama `schedule:run` a cada minuto, e quem decide o que
// roda e quando é este arquivo. Registre aqui e pronto — não precisa mexer no
// servidor de novo.
//
// Onde o cron mora: /etc/cron.d/nfs-schedule (instalado em 14/08/2026).
//
// Até essa data este comentário dizia que o cron existia, mas ele nunca tinha
// sido instalado — o crontab estava vazio. O sintoma seria mudo: nada aqui
// roda, e ninguém descobre até procurar o efeito que não aconteceu. Se um dia
// uma rotina agendada parecer não acontecer, conferir a linha é o primeiro
// passo:
//
//     sudo cat /etc/cron.d/nfs-schedule
//     sudo journalctl -u cron --since '-10 min' | grep schedule:run
//
// Exemplos do que mais caberia:
//     Schedule::command('queue:prune-failed --hours=168')->daily();
//     Schedule::call(fn() => /* resumo do dia por e-mail */)->dailyAt('18:00');
//
// A faxina dos anexos do chat: foto e documento saem do disco depois de
// alguns dias (Mensagem::DIAS_NO_SERVIDOR). Quem abriu dentro do prazo
// continua vendo — o navegador guardou a cópia dele.
//
// De madrugada de propósito: apagar arquivo mexe em disco, e às 3h a VM está
// parada — nenhum recebimento esperando a tela responder.
Schedule::command('chat:limpar-anexos')->dailyAt('03:20');

// Os prints dos cards de devolução, uma semana depois de o card ser conferido.
// O prazo conta da CONFERÊNCIA: enquanto ninguém conferiu, o print é a única
// coisa que permite conferir.
Schedule::command('devolucoes:limpar-anexos')->dailyAt('03:30');

// A janela de três semanas do chat: cada MENSAGEM sai 21 dias depois de ter
// sido mandada (Mensagem::DIAS_DE_VIDA). Não é uma zeragem periódica — a
// conversa vai perdendo o rabo enquanto ganha começo, e quem conversa todo dia
// sempre tem as últimas três semanas inteiras.
//
// Depois das outras duas de propósito: às 03:20 os anexos do chat já saíram do
// disco, então quando esta chega quase não há arquivo para apagar junto —
// sobra só o DELETE das linhas.
Schedule::command('chat:limpar-mensagens')->dailyAt('03:40');
