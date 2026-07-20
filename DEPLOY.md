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
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 7. nginx + HTTPS

Crie `/etc/nginx/sites-available/nfs` (ajuste o domínio):

```nginx
server {
    server_name nfs.SEU_DOMINIO;
    root /var/www/nfs/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

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
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always
[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nfs-reverb nfs-queue
```

## 9. Backup automático (mysqldump diário)

```bash
sudo mkdir -p /var/backups/nfs
sudo crontab -e
```
Adicione (backup às 2h, mantém 14 dias):
```cron
0 2 * * * mysqldump -u nfs_app -p'SENHA' sistema_notas | gzip > /var/backups/nfs/nfs-$(date +\%F).sql.gz && find /var/backups/nfs -name '*.sql.gz' -mtime +14 -delete
```

## 10. Primeiro acesso (dados reais)

1. Entre com o admin e **troque a senha** imediatamente.
2. Em **Usuários**, crie/ajuste as contas reais da equipe com os papéis certos
   (recebimento, pré-lote, compras).
3. Importe os **fornecedores reais** (endpoint `fornecedores.importar` aceita JSON).
4. Remova os **dados de demonstração** se tiver rodado o seeder
   (`notas`, `cards` fake e fornecedores inventados).
5. **Ensaio**: a equipe clica o fluxo inteiro (lançar → cards → corrigir →
   reconferir → liberar) antes de valer com nota real.

## Atualizações futuras

```bash
cd /var/www/nfs && git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart nfs-reverb nfs-queue
```
