# Deploy — VM Oracle (produção)

Runbook para colocar o sistema de NFs no ar na VM Oracle (a mesma do site da
Vanessa, que já tem nginx + HTTPS). Rode os comandos **no servidor**, via SSH.

Pré-requisitos já existentes na VM: Ubuntu, nginx, MySQL, PHP 8.2+, Composer,
Node 18+, git, certbot. Se faltar algum, instale antes.

---

## 1. Código e dependências

```bash
sudo mkdir -p /var/www/nfs && sudo chown $USER:$USER /var/www/nfs
git clone <URL_DO_REPO> /var/www/nfs
cd /var/www/nfs
git checkout melhorias/correcoes-papeis-e-refactor   # ou main, após o merge

composer install --no-dev --optimize-autoloader
npm ci
```

## 2. Banco: base + usuário dedicado (NÃO usar root)

```bash
sudo mysql
```
```sql
CREATE DATABASE sistema_notas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nfs_app'@'127.0.0.1' IDENTIFIED BY 'UMA_SENHA_FORTE_AQUI';
GRANT ALL PRIVILEGES ON sistema_notas.* TO 'nfs_app'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Configuração (.env)

```bash
cp .env.production.example .env
nano .env          # preencha os <TROCAR>: domínio, senha do banco, SMTP
php artisan key:generate
php artisan reverb:install    # gera REVERB_APP_ID/KEY/SECRET — copie para o .env
```
Depois de mexer no `.env`, os `VITE_REVERB_*` vão para o bundle no build (passo 5).

## 4. Migrations

```bash
php artisan migrate --force
```
A primeira conta de usuário criada vira admin automaticamente. Se o banco começar
vazio, crie o admin (passo 8).

## 5. Assets (build de produção)

```bash
npm run build
```
Importante: rode DEPOIS do `.env` pronto — o domínio do Reverb é compilado aqui.

## 6. Otimizações + permissões

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Permissões de `storage/` e `bootstrap/cache` — **dois** donos, não um:

```bash
sudo usermod -aG www-data ubuntu          # o deployer entra no grupo do servidor
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
```

Quem faz o deploy é o `ubuntu` (composer, artisan, build); quem serve o site é o
`www-data` (grava log e cache de framework). Os dois precisam escrever ali. Dar
tudo ao `www-data` — como dizia a receita antiga — funciona uma vez e trava o
deploy seguinte com `Permission denied` no log e em `bootstrap/cache`.

O `g+s` nos diretórios faz todo arquivo novo herdar o grupo `www-data`, então o
arranjo se mantém sozinho. O `usermod` só vale depois de sair e entrar de novo
no SSH.

## 7. nginx + HTTPS

Crie `/etc/nginx/sites-available/nfs` (ajuste o domínio):

```nginx
server {
    server_name nfs.SEU_DOMINIO;
    root /var/www/nfs/public;
    index index.php;

    # Cabeçalhos de segurança. A aplicação já manda os mesmos (middleware
    # CabecalhosDeSeguranca), mas o nginx entrega os arquivos estáticos sem
    # passar pelo PHP — estes aqui cobrem esse caminho também.
    # O HSTS só entra DEPOIS do certbot: mandá-lo antes de existir HTTPS tranca
    # o domínio num protocolo que ainda não funciona.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Compressão. O bundle é ~720 KB de texto (JS, CSS, JSON) e o gzip corta uns
    # 70% disso — é o ganho de carregamento mais barato que existe aqui.
    # image/svg+xml na lista importa: os 807 emojis de /emoji/ são SVG, ou seja,
    # texto. Sem ele viajam crus.
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied any;
    gzip_types text/plain text/css text/javascript application/javascript
               application/json application/xml image/svg+xml;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    # Cache dos estáticos. Nestes dois blocos NÃO use add_header: no nginx, um
    # location que declara qualquer add_header DESCARTA os herdados do server —
    # os cabeçalhos de segurança acima sumiriam justamente aqui. O `expires`
    # sozinho já emite o Cache-Control e não mexe nessa herança.
    location /build/ { expires 1y; }    # nome com hash: conteúdo nunca muda
    location /emoji/ { expires 30d; }   # nome estável, conteúdo praticamente fixo

    # WebSocket do Reverb (tempo real) — proxied como wss na mesma porta 443
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/nfs /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d nfs.SEU_DOMINIO      # HTTPS (Let's Encrypt)
```

## 8. Processos em segundo plano (Reverb + fila) via systemd

O Reverb e o worker de fila precisam ficar rodando sempre. Crie dois serviços.

⚠️ O worker **não é opcional**: os avisos do sino (`NotificacoesAtualizadas`)
passam pela fila. Com o `nfs-queue` parado, o sistema continua funcionando, mas
o sino deixa de atualizar sozinho — o aviso só aparece quando a pessoa recarrega
a página. Se alguém reclamar disso, `systemctl status nfs-queue` é a primeira
coisa a olhar.

`/etc/systemd/system/nfs-reverb.service`:
```ini
[Unit]
Description=NFs Reverb
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/nfs
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/nfs-queue.service`:
```ini
[Unit]
Description=NFs Queue Worker
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/nfs
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
[Install]
WantedBy=multi-user.target
```

O `--max-time=3600` faz o worker encerrar sozinho depois de uma hora, e o
`Restart=always` o levanta de novo. Processo PHP de vida longa vai acumulando
memória — numa VM de 1 GB isso termina em swap e lentidão geral. Reciclar de
hora em hora resolve sem ninguém precisar olhar. O worker só sai entre um job e
outro, então nada é interrompido no meio.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nfs-reverb nfs-queue
```

## 9. Backup automático (com cópia fora da VM)

Backup no mesmo disco que ele protege **não é backup**: se a VM morrer, some
tudo junto. O script [`scripts/backup.sh`](scripts/backup.sh) grava o dump
localmente (restore rápido) e manda uma cópia para o Object Storage da Oracle,
que também é Always Free.

### 9.1 Criar o bucket e o link de envio (uma vez)

No console da Oracle: **Storage → Buckets → Create Bucket** (nome: `nfs-backups`).

Depois, dentro do bucket, **Create Pre-Authenticated Request**:

| Campo | Valor |
|---|---|
| Target | Bucket |
| Access type | **Permit object writes** (só escrita) |
| Expiration | 1 ano (anote a data — expirou, o envio para) |

Copie a URL que aparece **na hora** — a Oracle não mostra de novo depois.

> Por que "só escrita": se alguém invadir a VM, o máximo que consegue é mandar
> arquivo novo. Não lê nem apaga os backups anteriores. Com uma chave de acesso
> completa, o invasor apagaria o backup junto com o banco.

### 9.2 Configurar e agendar

No `.env` do servidor:

```ini
BACKUP_PAR_URL=https://objectstorage.<regiao>.oraclecloud.com/p/<token>/n/<namespace>/b/nfs-backups/o/
```

```bash
sudo mkdir -p /var/backups/nfs
sudo crontab -e
```
```cron
0 2 * * * cd /var/www/nfs && bash scripts/backup.sh >> /var/log/nfs-backup.log 2>&1
```

Sem `BACKUP_PAR_URL` o script continua funcionando, só que grava um aviso de que
o backup existe apenas na VM.

### 9.3 Testar o restore (uma vez por mês)

```bash
sudo bash /var/www/nfs/scripts/testar-restore.sh /var/backups/nfs/nfs-2026-08-03-0200.sql.gz
```

> **O `sudo` não é opcional aqui.** Os backups das 02:00 são criados pelo cron do
> root com modo 600 — o dump traz e-mails e hashes de senha, e não pode ficar
> legível para qualquer usuário da máquina. Sem `sudo`, o `gzip` não abre o
> arquivo. (Os gerados pelo deploy pertencem ao `ubuntu` e abrem sem sudo, o que
> torna a diferença fácil de não perceber.)

Backup nunca restaurado é backup de fé: dump truncado, tabela faltando ou gzip
corrompido só aparecem no dia em que você precisa — o pior dia possível para
descobrir. O script restaura num banco **descartável**, confere as contagens
contra produção e apaga o banco de teste no fim. O de produção não é aberto em
momento nenhum.

## 10. Monitoramento (o que cai sem ninguém notar)

Reverb caído não quebra página nenhuma: a tela só para de atualizar sozinha, e
alguém reclama horas depois. O [`scripts/monitorar.sh`](scripts/monitorar.sh)
verifica a rota `/up`, a porta do Reverb e o worker da fila, reiniciando o que
estiver fora do ar.

```bash
sudo crontab -e
```
```cron
*/5 * * * * bash /var/www/nfs/scripts/monitorar.sh
```

(No cron do **root**, que é quem pode dar `systemctl restart`.)

### 10.1 Heartbeat — para saber quando a VM inteira cai

Monitor que roda dentro da VM nunca avisa que a VM caiu: a máquina morre e ele
morre junto, e o silêncio parece "tudo bem".

A saída é inverter a lógica. Crie um check gratuito no
[healthchecks.io](https://healthchecks.io) (ou equivalente), configure para
esperar um sinal a cada 15 minutos, e ponha a URL no `.env`:

```ini
MONITOR_HEARTBEAT_URL=https://hc-ping.com/<seu-uuid>
```

O script só envia o sinal quando está **tudo** de pé. É a AUSÊNCIA do sinal que
dispara o e-mail — então tanto um serviço caído quanto a VM desligada chegam até
você.

## 11. Agendador do Laravel

Uma única linha no cron liga o `schedule:run`, e a partir daí qualquer rotina
futura (resumo diário, limpeza de notificação antiga) é só registrar em
`routes/console.php` — sem mexer em servidor de novo.

**Já instalado em 14/08/2026**, em `/etc/cron.d/nfs-schedule` (e não no crontab
do usuário, para ficar junto do `nfs-backup` e sobreviver a troca de conta):

```cron
* * * * * ubuntu cd /var/www/nfs && php artisan schedule:run >> /dev/null 2>&1
```

Roda a cada minuto de propósito: quem decide o que executa e quando é o Laravel,
não o cron. Hoje ele serve a faxina dos anexos do chat (`chat:limpar-anexos`,
às 03:20).

> **Atenção ao instalar de novo (VM nova, restauração).** Esta seção existia
> desde o começo, mas a linha nunca tinha sido aplicada — e o `routes/console.php`
> afirmava que sim. Rotina agendada que não roda falha em silêncio: não dá erro,
> só não acontece. Depois de instalar, confirme que o cron disparou de verdade:
>
> ```bash
> sudo journalctl -u cron --since '-10 min' | grep schedule:run
> ```

## 12. Primeiro acesso (dados reais)

1. Entre com o admin e **troque a senha** imediatamente.
2. Em **Usuários**, crie/ajuste as contas reais da equipe com os papéis certos
   (recebimento, pré-lote, compras).
3. Importe os **fornecedores reais** (endpoint `fornecedores.importar` aceita JSON).
4. Remova os **dados de demonstração** se tiver rodado o seeder
   (`notas`, `cards` fake e fornecedores inventados).
5. **Ensaio**: a equipe clica o fluxo inteiro (lançar → cards → corrigir →
   reconferir → liberar) antes de valer com nota real.

## Atualizações futuras

São **dois** comandos, em duas máquinas. Se você não mexeu em tela (só PHP), o
primeiro é dispensável.

**1. Na sua máquina** — compila e envia os assets:

```bash
bash scripts/enviar-assets.sh
```

**2. No servidor** — o resto:

```bash
cd /var/www/nfs && git pull && bash scripts/deploy.sh
```

### Por que o build não roda no servidor

A VM é uma Always Free de 1 GB. O `vite build` é a etapa mais pesada do processo,
e um pico de memória lá não trava só o build: o OOM killer do Linux escolhe a
vítima pelo consumo, e os maiores candidatos são justamente o MySQL e o php-fpm.
Um build apertado derrubaria o site que ele deveria atualizar.

Por isso **não há Node instalado na VM**, e nem deve haver. O `deploy.sh` detecta
a ausência do npm, usa os assets já enviados e mostra a data do `manifest.json` —
se ela não bate com a sua última alteração de tela, você esqueceu do passo 1.

O script faz o backup do banco ANTES de qualquer outra coisa e só continua se o
dump sair de pé — se ele falhar, nada é alterado. Depois mostra as migrations
pendentes, atualiza código e dependências, roda `migrate --force`, recria os
caches e reinicia o Reverb e o worker.

Se preferir passo a passo, é o equivalente a:

```bash
cd /var/www/nfs && git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart nfs-reverb nfs-queue
```

⚠️ `migrate --force` só APLICA o que ainda não rodou — nunca apaga. Quem destrói
dado é `migrate:fresh`, `migrate:refresh`, `migrate:reset` e `db:wipe`: nenhum
deles tem lugar em produção.
