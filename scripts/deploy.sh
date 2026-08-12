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

# Reentrada: o `git pull` pode atualizar ESTE arquivo no meio da execução, e o
# bash lê o script sob demanda — ou seja, a partir do pull ele passaria a seguir
# um roteiro diferente do que começou (foi o que aconteceu em 03/08: a versão
# antiga exigia npm na VM e parou no meio, mesmo com a nova já em disco).
# Solução: se o próprio script mudou no pull, ele se re-executa na versão nova.
# Esta variável evita repetir o backup e barra um laço infinito de re-execução.
REENTRADA="${NFS_DEPLOY_REENTRADA:-0}"

# ─── Manutenção: erro 500 vira página de aviso ────────────────────────────────
#
# O `git pull` põe o código novo no ar ANTES de a migration rodar. Nesse vão de
# alguns segundos o sistema pede colunas que ainda não existem, e quem estiver
# com a tela aberta toma erro 500 sem explicação — foi o que aconteceu em
# 11/08, com dois usuários batendo no erro em 4 segundos.
#
# `artisan down` não evita a interrupção: troca o erro pela página de
# manutenção, que se resolve sozinha quando o deploy acaba. Não encosta no
# banco — só cria storage/framework/down.
#
# Vem DEPOIS do backup de propósito: backup que falha derruba o script, e não
# se pode deixar o site parado por causa disso.

restaurar_site() {
    local saida=$?

    # `artisan up` precisa que a aplicação suba. Se o pull trouxe código que nem
    # inicializa, ele falha junto e o site ficaria preso na manutenção — daí o
    # plano B de apagar o arquivo na unha, que é tudo que o `up` faz.
    php artisan up > /dev/null 2>&1 || rm -f storage/framework/down

    if [[ "${saida}" -ne 0 ]]; then
        echo
        echo "✗ O deploy falhou (código ${saida}) — mas o site foi religado." >&2
        echo "  O backup desta rodada está de pé; confira o erro acima antes de repetir." >&2
    fi
}

iniciar_manutencao() {
    echo "→ Entrando em manutenção (o site volta sozinho ao fim do deploy)..."
    php artisan down --retry=60 > /dev/null 2>&1 || true

    # A partir daqui, qualquer saída — sucesso, erro ou Ctrl+C — religa o site.
    trap restaurar_site EXIT
}

if [[ "${REENTRADA}" == "0" ]]; then

    # ─── 1. Backup (pré-condição, não etapa) ──────────────────────────────────

    # Reaproveita o mesmo backup.sh do cron diário, em vez de ter uma segunda
    # receita de dump aqui: assim o backup de antes do deploy segue o mesmo
    # caminho testado pelo testar-restore.sh — e também vai para fora da VM.
    #
    # O `set -e` cuida do resto: se o backup falhar (dump vazio, disco cheio, PAR
    # expirado), o script morre nesta linha, antes de tocar em código ou banco.
    echo "→ Backup antes de qualquer alteração..."
    bash scripts/backup.sh

    # O `|| true` evita um falso negativo: o `ls` pode levar SIGPIPE quando o
    # `head` fecha a entrada, e com pipefail isso derrubaria o deploy depois de o
    # backup ter dado certo.
    ARQUIVO="$(ls -1t /var/backups/nfs/nfs-*.sql.gz 2>/dev/null | head -1 || true)"

    # Backup de pé: agora sim dá para fechar o site sem risco de deixá-lo
    # parado por causa de uma pré-condição que falhou.
    iniciar_manutencao

    # ─── 2. O que a migration vai fazer (à vista, antes de fazer) ─────────────

    echo
    echo "→ Migrations pendentes:"
    php artisan migrate:status | grep -i pending || echo "  (nenhuma)"
    echo

    # ─── 3. Código (o pull vem ANTES de decidir os passos seguintes) ──────────

    ASSINATURA_ANTES="$(sha1sum "${BASH_SOURCE[0]}" | cut -d' ' -f1)"

    echo "→ Atualizando código..."
    git pull

    ASSINATURA_DEPOIS="$(sha1sum "${BASH_SOURCE[0]}" | cut -d' ' -f1)"

    if [[ "${ASSINATURA_ANTES}" != "${ASSINATURA_DEPOIS}" ]]; then
        echo "→ O próprio deploy.sh mudou neste pull — recomeçando pela versão nova."
        echo "  (backup e git pull já estão feitos; não se repetem)"
        echo
        # `exec` troca o processo: o roteiro passa a ser lido do arquivo novo,
        # do começo, sem sobrar nada do antigo em memória.
        NFS_DEPLOY_REENTRADA=1 NFS_DEPLOY_BACKUP="${ARQUIVO}" \
            exec bash "${BASH_SOURCE[0]}"
    fi

else
    # Segunda passagem: backup e pull já aconteceram na primeira.
    ARQUIVO="${NFS_DEPLOY_BACKUP:-(feito na primeira passagem)}"
    echo "→ Seguindo na versão atualizada do deploy.sh."
    echo

    # O `exec` da primeira passagem trocou o processo, e com ele foi embora o
    # trap — o site continua em manutenção, mas sem ninguém encarregado de
    # religá-lo. Reassumimos aqui. O `down` repetido não faz mal.
    iniciar_manutencao
fi

echo "→ Dependências PHP..."
composer install --no-dev --optimize-autoloader

# O npm quase sempre vem do nvm, que se instala no ~/.bashrc — e o ~/.bashrc NÃO
# roda em shell não-interativo, que é o caso deste script (e de qualquer chamada
# por cron). Daí o sintoma confuso: `npm` funciona quando você digita no terminal
# e some com "command not found" quando o script chama.
if ! command -v npm > /dev/null 2>&1; then
    for init in "${HOME}/.nvm/nvm.sh" /usr/local/nvm/nvm.sh /opt/nvm/nvm.sh; do
        if [[ -s "${init}" ]]; then
            # shellcheck source=/dev/null
            . "${init}"
            break
        fi
    done
fi

if command -v npm > /dev/null 2>&1; then
    echo "→ Dependências JS + build... (npm: $(command -v npm))"
    npm ci
    npm run build
else
    # Sem Node nesta VM, e é de propósito: o `vite build` é a etapa mais pesada
    # do deploy, e num servidor de 1 GB o OOM killer escolhe a vítima pelo
    # consumo — o MySQL e o php-fpm são os primeiros da fila. Os assets são
    # compilados na máquina de desenvolvimento e enviados prontos
    # (scripts/enviar-assets.sh).
    if [[ ! -f public/build/manifest.json ]]; then
        echo "✗ Sem npm nesta máquina E sem public/build/manifest.json." >&2
        echo "  Não há assets para servir. O backup está de pé e o banco não foi tocado." >&2
        echo "  Na SUA máquina, rode:  bash scripts/enviar-assets.sh" >&2
        exit 1
    fi

    # A data é o que separa "assets prontos" de "assets esquecidos". Sem ela, um
    # build de três semanas atrás passaria por atual sem ninguém desconfiar.
    echo "→ Build: usando os assets já enviados."
    echo "  manifest.json de $(date -r public/build/manifest.json '+%d/%m/%Y %H:%M')"
    echo "  (se essa data não bate com a sua última alteração de tela, rode"
    echo "   'bash scripts/enviar-assets.sh' na sua máquina antes de seguir)"
fi

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

# DOIS donos, não um. Quem faz o deploy é o `ubuntu` (composer, artisan, build);
# quem serve o site é o `www-data` (grava log, cache de framework). Entregar tudo
# para o www-data — como fazia a receita antiga — funcionava uma vez e travava o
# deploy SEGUINTE, porque o ubuntu perdia a escrita em storage/ e bootstrap/cache.
#
# Vai DEPOIS dos comandos do artisan de propósito: assim os arquivos recém-criados
# por eles (log do dia, caches) também entram no ajuste.
echo "→ Permissões..."
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

echo "→ Reiniciando Reverb e worker..."
sudo systemctl restart nfs-reverb nfs-queue

# ─── 6. Site de volta ─────────────────────────────────────────────────────────
#
# Só aqui: o banco já está migrado, os caches recriados e os serviços de pé.
# Religar antes disso seria devolver ao usuário exatamente o estado que a
# manutenção existe para esconder.
#
# O trap continua armado e chamaria isto de novo na saída — `artisan up` com o
# site já no ar não faz nada, então repetir é inofensivo.
echo "→ Saindo da manutenção..."
php artisan up > /dev/null 2>&1 || rm -f storage/framework/down

echo
echo "✓ Deploy concluído."
echo "  Backup desta rodada: ${ARQUIVO}"
echo "  (o backup.sh já mandou a cópia para o Object Storage, se o PAR estiver configurado)"
