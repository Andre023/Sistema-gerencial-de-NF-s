#!/usr/bin/env bash
#
# Compila os assets AQUI (na sua máquina) e envia o resultado para a VM.
#
# Por que não compilar no servidor: a VM é uma Always Free de 1 GB de RAM. O
# `vite build` é a etapa mais pesada de todo o processo, e um pico de memória lá
# não trava só o build — o OOM killer do Linux escolhe a vítima pelo consumo, e
# os maiores candidatos são justamente o MySQL e o php-fpm. Ou seja: um build
# apertado pode derrubar o site que ele deveria atualizar.
#
# Compilando na máquina de desenvolvimento, a VM só recebe arquivo pronto — e
# nem precisa ter Node instalado.
#
# Uso (a partir da raiz do projeto, no Git Bash):
#     bash scripts/enviar-assets.sh
#
# Configuração por variável de ambiente (com padrões para esta instalação):
#     NFS_SSH_HOST=ubuntu@163.176.154.52
#     NFS_SSH_KEY="/c/sistema gerencial nf/ssh-key-2026-07-20.key"
#
set -euo pipefail

SSH_HOST="${NFS_SSH_HOST:-ubuntu@163.176.154.52}"
SSH_KEY="${NFS_SSH_KEY:-/c/sistema gerencial nf/ssh-key-2026-07-20.key}"
DESTINO="/var/www/nfs/public/"

cd "$(dirname "$0")/.."

# ─── 1. Build ─────────────────────────────────────────────────────────────────

echo "→ Compilando os assets..."
npm run build

if [[ ! -f public/build/manifest.json ]]; then
    echo "✗ O build não gerou public/build/manifest.json. Nada foi enviado." >&2
    exit 1
fi

echo "✓ Build pronto ($(du -sh public/build | cut -f1))"

# ─── 2. Envio ─────────────────────────────────────────────────────────────────

OPCOES_SSH=()
if [[ -n "${SSH_KEY}" ]]; then
    if [[ ! -f "${SSH_KEY}" ]]; then
        echo "✗ Chave não encontrada em: ${SSH_KEY}" >&2
        echo "  Ajuste com: export NFS_SSH_KEY=/caminho/da/chave" >&2
        exit 1
    fi
    OPCOES_SSH=(-i "${SSH_KEY}")
fi

echo "→ Enviando para ${SSH_HOST}:${DESTINO}build ..."
# -r porque é a pasta inteira. Os nomes têm hash, então arquivo novo nunca
# sobrescreve o antigo: quem estiver com a página velha aberta continua achando
# os pedaços dela até recarregar.
scp "${OPCOES_SSH[@]}" -r public/build "${SSH_HOST}:${DESTINO}"

echo
echo "✓ Assets enviados."
echo
echo "Agora, no servidor:"
echo "    cd /var/www/nfs && git pull && bash scripts/deploy.sh"
echo
echo "Com o tempo, os assets antigos se acumulam em public/build/assets (nome com"
echo "hash, nada é sobrescrito). De vez em quando vale limpar a pasta na VM e"
echo "reenviar — mas só com o site parado ou fora do horário de uso, porque apaga"
echo "os pedaços de quem estiver com a página aberta."
