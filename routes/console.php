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
