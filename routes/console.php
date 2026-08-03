<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
// Hoje não há nada agendado, e está tudo bem: a linha do cron existe para o dia
// em que houver.
