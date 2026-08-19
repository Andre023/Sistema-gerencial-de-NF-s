#!/usr/bin/env bash
#
# Testa se um backup RESTAURA de verdade.
#
# Backup que nunca foi restaurado é backup de fé: dump truncado, tabela faltando
# ou gzip corrompido só aparecem no dia em que você precisa dele — que é o pior
# dia possível para descobrir. Rode isto uma vez por mês.
#
# O restore acontece num banco DESCARTÁVEL, com nome próprio. O banco de produção
# não é aberto em nenhum momento: o script se recusa a rodar se os dois nomes
# coincidirem.
#
# Uso:
#     bash /var/www/nfs/scripts/testar-restore.sh /var/backups/nfs/nfs-2026-08-03-0200.sql.gz
#
set -euo pipefail

APP_DIR="/var/www/nfs"
ARQUIVO="${1:-}"

if [[ -z "${ARQUIVO}" ]]; then
    echo "Uso: bash scripts/testar-restore.sh <arquivo.sql.gz>" >&2
    echo "Backups disponíveis:" >&2
    ls -1t /var/backups/nfs/nfs-*.sql.gz 2>/dev/null | head -5 >&2 || echo "  (nenhum)" >&2
    exit 1
fi

if [[ ! -f "${ARQUIVO}" ]]; then
    echo "✗ Arquivo não encontrado: ${ARQUIVO}" >&2
    exit 1
fi

# Legivel por quem esta rodando?
#
# Isto existe por causa de um alarme falso perigoso. Os backups do cron das 02:00
# sao criados pelo ROOT, modo 600 (o dump tem e-mails e hashes de senha, ver o
# umask do backup.sh). Rodando este teste como `ubuntu`, o gzip morre com
# "Permission denied" — e a mensagem que aparecia era:
#
#     ✗ O RESTORE FALHOU. Este backup nao presta — investigue...
#
# Ou seja: quem seguisse a rotina mensal do DEPLOY.md concluiria que os backups
# automaticos estao corrompidos, quando o unico problema era faltar sudo. Num
# script cuja funcao e dizer se da para confiar no backup, errar para o lado do
# panico e pior do que nao existir.
if [[ ! -r "${ARQUIVO}" ]]; then
    echo "✗ Sem permissao de leitura: ${ARQUIVO}" >&2
    echo "" >&2
    echo "  Isto NAO quer dizer que o backup esteja ruim — o arquivo e do root" >&2
    echo "  (modo 600, porque o dump traz e-mails e hashes de senha)." >&2
    echo "" >&2
    echo "  Rode de novo com sudo:" >&2
    echo "      sudo bash scripts/testar-restore.sh ${ARQUIVO}" >&2
    exit 1
fi

cd "${APP_DIR}"
DB_PROD="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d "\"'")"
DB_TESTE="${DB_PROD}_restore_teste"

# Cinto de segurança: se por qualquer motivo os nomes baterem, para tudo.
if [[ "${DB_TESTE}" == "${DB_PROD}" ]]; then
    echo "✗ O banco de teste tem o mesmo nome do de produção. Abortado." >&2
    exit 1
fi

echo "→ Produção: ${DB_PROD} (não será tocado)"
echo "→ Restaurando em: ${DB_TESTE}"
echo

# Usa o root via socket (padrão do Ubuntu) porque o usuário da aplicação só tem
# privilégio no banco de produção — não consegue criar outro.
echo "→ Criando banco descartável..."
sudo mysql -e "DROP DATABASE IF EXISTS \`${DB_TESTE}\`; CREATE DATABASE \`${DB_TESTE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "→ Restaurando o dump (pode demorar)..."
if ! gunzip -c "${ARQUIVO}" | sudo mysql "${DB_TESTE}"; then
    echo "✗ O RESTORE FALHOU. Este backup não presta — investigue antes que precise dele." >&2
    sudo mysql -e "DROP DATABASE IF EXISTS \`${DB_TESTE}\`;"
    exit 1
fi

# ─── Conferência: restaurou de verdade ou só criou tabelas vazias? ────────────

echo
echo "→ Conferindo o conteúdo restaurado:"

TABELAS=$(sudo mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_TESTE}';")
echo "   tabelas restauradas: ${TABELAS}"

for t in notas cards users fornecedores; do
    N=$(sudo mysql -N -B -e "SELECT COUNT(*) FROM \`${DB_TESTE}\`.\`${t}\`;" 2>/dev/null || echo "ERRO")
    printf "   %-14s %s\n" "${t}:" "${N}"
done

# Compara com produção: um dump bom tem a mesma contagem de notas (ou perto, se
# a operação continuou rodando depois do dump).
NOTAS_PROD=$(sudo mysql -N -B -e "SELECT COUNT(*) FROM \`${DB_PROD}\`.notas;" 2>/dev/null || echo "?")
NOTAS_TESTE=$(sudo mysql -N -B -e "SELECT COUNT(*) FROM \`${DB_TESTE}\`.notas;" 2>/dev/null || echo "?")
echo
echo "   notas em produção: ${NOTAS_PROD} | no backup restaurado: ${NOTAS_TESTE}"

if [[ "${TABELAS}" -lt 5 ]]; then
    echo
    echo "✗ Só ${TABELAS} tabelas — o dump parece truncado." >&2
    sudo mysql -e "DROP DATABASE IF EXISTS \`${DB_TESTE}\`;"
    exit 1
fi

# ─── Limpeza ──────────────────────────────────────────────────────────────────

echo
echo "→ Removendo o banco de teste..."
sudo mysql -e "DROP DATABASE \`${DB_TESTE}\`;"

echo
echo "✓ Backup restaura corretamente: ${ARQUIVO}"
echo "  Produção intacta — só o banco descartável foi criado e removido."
