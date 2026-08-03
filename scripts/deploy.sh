#!/usr/bin/env bash
#
# Deploy do sistema de NFs na VM Oracle.
#
# Existe para tirar o deploy do copiar-e-colar: a sequência do DEPLOY.md tem sete
# comandos, e é justamente no meio de uma sequência longa digitada à mão que
# alguém pula um passo ou troca `migrate` por `migrate:fresh`.
#
# A regra da casa está na ordem: o backup vem ANTES, e se ele não sair de pé o
# script morre aqui mesmo, sem tocar em código nem em banco.
#
# Uso, no servidor:
#     cd /var/www/nfs && ./scripts/deploy.sh
#
set -euo pipefail

APP_DIR="/var/www/nfs"

cd "${APP_DIR}"

# ─── 1. Backup (pré-condição, não etapa) ──────────────────────────────────────

# Reaproveita o mesmo backup.sh do cron diário, em vez de ter uma segunda receita
# de dump aqui: assim o backup de antes do deploy segue o mesmo caminho testado
# pelo testar-restore.sh — e também vai para fora da VM.
#
# O `set -e` cuida do resto: se o backup falhar (dump vazio, disco cheio, PAR
# expirado), o script morre nesta linha, antes de tocar em código ou banco.
echo "→ Backup antes de qualquer alteração..."
bash scripts/backup.sh

# O `|| true` evita um falso negativo: o `ls` pode levar SIGPIPE quando o `head`
# fecha a entrada, e com pipefail isso derrubaria o deploy depois de o backup ter
# dado certo.
ARQUIVO="$(ls -1t /var/backups/nfs/nfs-*.sql.gz 2>/dev/null | head -1 || true)"

# ─── 2. O que a migration vai fazer (à vista, antes de fazer) ─────────────────

echo
echo "→ Migrations pendentes:"
php artisan migrate:status | grep -i pending || echo "  (nenhuma)"
echo

# ─── 3. Código e dependências ─────────────────────────────────────────────────

echo "→ Atualizando código..."
git pull

echo "→ Dependências PHP..."
composer install --no-dev --optimize-autoloader

echo "→ Dependências JS + build..."
npm ci
npm run build

# ─── 4. Banco ─────────────────────────────────────────────────────────────────

# --force quer dizer "não me pergunte se é produção" — e não "force bruta".
# Aplica só o que ainda não rodou; nunca desfaz nem apaga.
echo "→ Migrations..."
php artisan migrate --force

# ─── 5. Caches e processos ────────────────────────────────────────────────────

echo "→ Recriando caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ Permissões..."
sudo chown -R www-data:www-data storage bootstrap/cache

echo "→ Reiniciando Reverb e worker..."
sudo systemctl restart nfs-reverb nfs-queue

echo
echo "✓ Deploy concluído."
echo "  Backup desta rodada: ${ARQUIVO}"
echo "  (o backup.sh já mandou a cópia para o Object Storage, se o PAR estiver configurado)"
