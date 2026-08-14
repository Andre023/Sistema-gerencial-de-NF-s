<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Rotinas agendadas ────────────────────────────────────────────────────────
//
// O cron do servidor chama `schedule:run` a cada minuto (DEPLOY.md, seção 11);
// quem decide o que roda e quando é este arquivo. Registre aqui e pronto — não
// precisa mexer no servidor de novo.
//
// Exemplos do que caberia:
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
