#!/usr/bin/env bash
#
# Backup diário do banco, com cópia FORA da VM.
#
# O backup que mora no mesmo disco que ele protege não é backup: se a VM morre,
# some tudo junto. Aqui o dump é gravado localmente (restore rápido) e enviado
# para o Object Storage da Oracle (restore mesmo depois de perder a máquina).
#
# O envio usa um PAR — Pre-Authenticated Request, um link temporário que a Oracle
# gera para um bucket. Duas vantagens sobre instalar a CLI da OCI: não precisa de
# chave nem SDK na VM, e o PAR pode ser criado como SOMENTE ESCRITA — assim, quem
# invadir o servidor consegue no máximo mandar arquivo novo, nunca ler nem apagar
# os backups anteriores. Como criar está no DEPLOY.md, seção 9.
#
# Uso (via cron, ver DEPLOY.md):
#     bash /var/www/nfs/scripts/backup.sh
#
set -euo pipefail

# O dump é o sistema inteiro em texto: notas, e-mails, hashes de senha. Nascendo
# com a permissão padrão (644) qualquer usuário da máquina lê. O umask aqui faz
# TODO arquivo criado por este script sair 600 — inclusive os das próximas noites.
# (Ajustar a permissão só dos arquivos existentes não resolve: o script recria.)
umask 077

APP_DIR="/var/www/nfs"
BACKUP_DIR="/var/backups/nfs"
MANTER_DIAS=14
CARIMBO="$(date +%F-%H%M)"

cd "${APP_DIR}"

le_env() {
    grep -E "^${1}=" .env | head -1 | cut -d= -f2- | tr -d "\"'"
}

# O `|| true` em todas: sem ele, uma chave ausente faz o grep devolver erro e o
# `set -e` mata o script AQUI — a mensagem amigável logo abaixo nunca apareceria,
# e o cron registraria uma falha muda.
DB_NOME="$(le_env DB_DATABASE || true)"
DB_USER="$(le_env DB_USERNAME || true)"
DB_SENHA="$(le_env DB_PASSWORD || true)"
# Opcional: se estiver vazio, o backup é só local (com aviso).
PAR_URL="$(le_env BACKUP_PAR_URL || true)"

if [[ -z "${DB_NOME}" || -z "${DB_USER}" ]]; then
    echo "✗ Não achei DB_DATABASE/DB_USERNAME no .env." >&2
    exit 1
fi

# ─── 1. Dump ──────────────────────────────────────────────────────────────────

mkdir -p "${BACKUP_DIR}"
NOME="nfs-${CARIMBO}.sql.gz"
ARQUIVO="${BACKUP_DIR}/${NOME}"

# MYSQL_PWD em vez de -p: senha na linha de comando fica visível em `ps`.
# --single-transaction tira o dump sem travar as tabelas (InnoDB).
# --no-tablespaces: sem ele o mysqldump tenta ler metadado de tablespace, que no
# MySQL 8 exige o privilégio PROCESS — global, que o usuário da aplicação não tem
# (e não deve ter). O dump saía completo assim mesmo, mas cuspindo um "Access
# denied" a cada execução: erro de mentira no log é erro de verdade escondido.
MYSQL_PWD="${DB_SENHA}" mysqldump \
    --user="${DB_USER}" \
    --single-transaction \
    --no-tablespaces \
    --routines \
    --triggers \
    "${DB_NOME}" | gzip > "${ARQUIVO}"

# Dump que falhou no meio ainda deixa arquivo — só que minúsculo.
TAMANHO=$(stat -c%s "${ARQUIVO}")
if (( TAMANHO < 10000 )); then
    echo "✗ Dump saiu com ${TAMANHO} bytes — pequeno demais para ser verdade." >&2
    rm -f "${ARQUIVO}"
    exit 1
fi

echo "✓ Dump local: ${ARQUIVO} ($(du -h "${ARQUIVO}" | cut -f1))"

# ─── 2. Cópia fora da VM ──────────────────────────────────────────────────────

if [[ -z "${PAR_URL}" ]]; then
    echo "⚠ BACKUP_PAR_URL não configurado no .env — o backup existe SÓ nesta VM."
    echo "  Se perder a máquina, perde o backup junto. Ver DEPLOY.md, seção 9."
else
    # Garante a barra final: o PAR aponta para o "diretório" e o nome vem depois.
    [[ "${PAR_URL}" != */ ]] && PAR_URL="${PAR_URL}/"

    # --fail faz o curl devolver erro em resposta HTTP 4xx/5xx; sem ele, um PAR
    # expirado passaria batido e o script diria que enviou.
    if curl --fail --silent --show-error --max-time 300 \
            -X PUT --upload-file "${ARQUIVO}" "${PAR_URL}${NOME}"; then
        echo "✓ Enviado para o Object Storage: ${NOME}"
    else
        echo "✗ FALHOU o envio para o Object Storage — o dump local está de pé," >&2
        echo "  mas não há cópia fora da VM. Verifique se o PAR expirou." >&2
        exit 1
    fi
fi

# ─── 3. Limpeza dos antigos (só do disco local) ───────────────────────────────

# O que está no Object Storage NÃO é apagado aqui de propósito: o PAR é de
# escrita, e a retenção lá se configura por regra de ciclo de vida do bucket.
find "${BACKUP_DIR}" -name 'nfs-*.sql.gz' -mtime "+${MANTER_DIAS}" -delete

echo "✓ Backup concluído. Locais mantidos: $(ls -1 "${BACKUP_DIR}"/nfs-*.sql.gz 2>/dev/null | wc -l)"
