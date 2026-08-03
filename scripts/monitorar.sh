#!/usr/bin/env bash
#
# Vigia os três pedaços que podem cair sem ninguém perceber.
#
# O sintoma dessas quedas é silencioso: a tela simplesmente para de atualizar
# sozinha, e alguém reclama horas depois — quando já atrapalhou o dia. Nenhum
# erro aparece, nenhuma página quebra.
#
#   • a aplicação   → a rota /up (health check do Laravel)
#   • o Reverb      → a porta do WebSocket aceita conexão?
#   • o worker      → o systemd diz que está ativo?
#
# Quando algo cai, o script reinicia o serviço e registra no log. O systemd já
# tem Restart=always, mas isso só cobre processo que MORRE — não o que fica de pé
# sem responder, que é o caso chato.
#
# ─── Por que só isto não basta ───────────────────────────────────────────────
#
# Um monitor que roda DENTRO da VM nunca vai te avisar que a VM caiu: se a
# máquina morre, o monitor morre com ela e o silêncio parece "tudo bem".
#
# A saída é inverter a lógica — o "dead man's switch": quando está tudo certo, o
# script avisa um serviço externo ("estou vivo"). É a AUSÊNCIA do aviso que
# dispara o alerta. Assim, tanto um serviço caído quanto a VM inteira desligada
# chegam até você. Configure MONITOR_HEARTBEAT_URL no .env (ver DEPLOY.md).
#
# Uso (cron do root, a cada 5 min — ver DEPLOY.md):
#     bash /var/www/nfs/scripts/monitorar.sh
#
set -uo pipefail   # sem -e: um check que falha não pode abortar os outros

APP_DIR="/var/www/nfs"
LOG="/var/log/nfs-monitor.log"

cd "${APP_DIR}"

le_env() {
    grep -E "^${1}=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"'"
}

APP_URL="$(le_env APP_URL)"
REVERB_PORT="$(le_env REVERB_SERVER_PORT)"
REVERB_PORT="${REVERB_PORT:-8080}"
HEARTBEAT="$(le_env MONITOR_HEARTBEAT_URL)"

registrar() {
    echo "[$(date '+%F %T')] $*" >> "${LOG}"
}

PROBLEMAS=0

# ─── 1. A aplicação responde? ────────────────────────────────────────────────

# Bate na URL pública de propósito: é o caminho que o usuário percorre, então
# testa nginx, PHP-FPM e certificado de uma vez.
if ! curl --fail --silent --show-error --max-time 15 "${APP_URL}/up" > /dev/null 2>&1; then
    registrar "FALHA: ${APP_URL}/up não respondeu. Reiniciando php8.2-fpm e nginx."
    systemctl restart php8.2-fpm nginx
    PROBLEMAS=$((PROBLEMAS + 1))
fi

# ─── 2. O Reverb aceita conexão? ─────────────────────────────────────────────

# Teste de TCP puro com o /dev/tcp do bash — não precisa de nc nem telnet
# instalados. Processo vivo mas travado não aceita conexão, e é justamente esse
# o caso que o Restart=always do systemd não pega.
if ! timeout 5 bash -c "echo > /dev/tcp/127.0.0.1/${REVERB_PORT}" 2>/dev/null; then
    registrar "FALHA: Reverb não aceita conexão na porta ${REVERB_PORT}. Reiniciando."
    systemctl restart nfs-reverb
    PROBLEMAS=$((PROBLEMAS + 1))
fi

# ─── 3. O worker da fila está ativo? ─────────────────────────────────────────

# Sem ele, o sino para de atualizar sozinho (os avisos passam pela fila).
if ! systemctl is-active --quiet nfs-queue; then
    registrar "FALHA: nfs-queue parado. Reiniciando."
    systemctl restart nfs-queue
    PROBLEMAS=$((PROBLEMAS + 1))
fi

# ─── Heartbeat: só quando está TUDO de pé ────────────────────────────────────

if (( PROBLEMAS > 0 )); then
    registrar "${PROBLEMAS} problema(s) detectado(s) e serviço(s) reiniciado(s)."
    # De propósito NÃO manda o heartbeat: o silêncio é o que vai te alertar.
    exit 1
fi

if [[ -n "${HEARTBEAT}" ]]; then
    curl --fail --silent --max-time 10 "${HEARTBEAT}" > /dev/null 2>&1 \
        || registrar "AVISO: não consegui enviar o heartbeat (o sistema está de pé)."
fi

exit 0
