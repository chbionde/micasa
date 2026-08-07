# infra/ — provisionamento e deploy

Runbook da VPS do MiCasa. Decisões que embasam este diretório: **ADR-003** (Oracle Always Free, sem PAYG) com a emenda de 2026-08-07 (região Vinhedo, forma AMD `E2.1.Micro`) e **ADR-020** (origem única e fronteira de caminhos).

## O que existe aqui

| Arquivo | Papel |
|---|---|
| `provision.sh` | Roda **uma vez** numa VPS nova. Deixa a máquina pronta, sem a aplicação. |
| `deploy.sh` | Roda **a cada** publicação. Atualiza código, dependências, banco e caches. |
| `nginx/micasa.conf.template` | Site do nginx. O `provision.sh` substitui os `__PLACEHOLDER__`. |
| `systemd/micasa-queue.service` | Worker da fila. |
| `cron/micasa-scheduler` | Entrada única de cron do scheduler do Laravel. |
| `env.production.example` | Modelo do `api/.env` de produção. |

## Pré-requisitos na nuvem

Feitos no console da Oracle, fora deste script:

- Instância `VM.Standard.E2.1.Micro`, Ubuntu 24.04, em sub-rede **pública**
- **IP público reservado** (não efêmero) — sem isso o endereço muda quando a instância é parada e religada, e DNS e certificado quebram junto
- Lista de Segurança da VCN com entrada TCP em **80** e **443**, origem `0.0.0.0/0`
- DNS apontando para o IP reservado

> Liberar as portas na Lista de Segurança **não basta**. As imagens Ubuntu da Oracle trazem regras `iptables` que descartam tudo além de SSH; quem resolve isso é o `provision.sh`.

## Primeira vez

```bash
# 1. Clone o repositório no lugar definitivo
sudo mkdir -p /var/www/micasa
sudo chown "$USER:www-data" /var/www/micasa
git clone https://github.com/chbionde/micasa.git /var/www/micasa
cd /var/www/micasa

# 2. Provisione (fuso, swap, iptables, PHP, nginx, fila, scheduler, TLS)
sudo DOMINIO=micasa-bionde.duckdns.org EMAIL_TLS=voce@exemplo.com ./infra/provision.sh

# 3. Configure a aplicação
cp infra/env.production.example api/.env
nano api/.env                      # confira APP_URL, FRONTEND_URL, e-mail

# 4. Instale as dependências e gere a chave
#    Nesta ordem: o key:generate roda pelo artisan, que precisa do vendor/.
cd api
composer install --no-dev --optimize-autoloader
php artisan key:generate
cd ..

# 5. Publique
./infra/deploy.sh
sudo systemctl start micasa-queue
```

O `deploy.sh` repete o `composer install` do passo 4 — na segunda vez ele não baixa nada e custa segundos. A repetição é preferível a um deploy que só funciona depois de alguém ter rodado o passo certo à mão.

## Ensaio fora da VPS

`PULAR_AJUSTES_DE_HOST=1` desliga as quatro seções que só existem por causa da máquina real — fuso, swap, `iptables` e `certbot` — e deixa rodar o resto num WSL ou numa VM descartável. Serve para descobrir erro de digitação e de caminho antes de tocar no servidor.

```bash
sudo PULAR_AJUSTES_DE_HOST=1 DOMINIO=micasa.localhost ./infra/provision.sh
```

`EMAIL_TLS` é dispensado nesse modo: ele só alimenta o certbot. Exige systemd (`/etc/wsl.conf` com `[boot] systemd=true`, depois `wsl --shutdown`).

**Nunca use esta variável na VPS.** O que sai dela não é um servidor de produção: sem swap, o FPM cai no primeiro pico; sem a regra de `iptables`, a porta 80 dá timeout; sem certificado, não há HTTPS.

O que o ensaio **cobre**: nginx e o roteamento de origem única do ADR-020, permissões, o WAL criando `-wal`/`-shm`, `composer install`, `migrate`, `config:cache`, a unit da fila e o `deploy.sh` inteiro. É justamente o que falha em silêncio.

O que ele **não cobre**:

| Seção | Por quê |
|---|---|
| `iptables` | As regras DROP vêm da imagem Ubuntu da Oracle. Fora dela não há o que corrigir — o "funcionou" é vazio |
| Swap | O WSL2 gerencia swap na própria VM; `swapon` devolve `Operation not permitted` |
| `certbot` | O desafio HTTP-01 precisa alcançar a porta 80 pelo nome do domínio; o WSL está atrás de NAT |
| Pressão de 1 GB | A máquina de ensaio tem RAM de sobra. O motivo de `ondemand`, do swap e de não buildar o front no servidor não aparece |

## Publicando o front

O `deploy.sh` **não** roda `npm run build`. Vite mais `tsc` não cabem com folga em 1 GB de RAM e 1/8 de OCPU, e um build que mata a máquina por falta de memória é pior que um passo manual.

Construa fora e envie o resultado:

```bash
# na sua máquina
cd web
npm run build
scp -i ~/.ssh/id_ed25519 -r dist/* ubuntu@SEU_IP:/var/www/micasa/web/dist/
```

Não é preciso recarregar o nginx: são arquivos estáticos, servidos direto do disco.

> Automatizar isso no GitHub Actions é trabalho de uma issue própria. Enquanto não existir, este é o caminho — e ele funciona.

## Publicações seguintes

```bash
cd /var/www/micasa && ./infra/deploy.sh
```

## Verificando

```bash
curl -sS https://micasa-bionde.duckdns.org/up      # health check do Laravel
systemctl status micasa-queue                      # worker da fila
journalctl -u micasa-queue -n 50                   # log do worker
sudo tail -f /var/log/nginx/error.log              # erros do nginx
free -h                                            # RAM e swap
```

## Quando algo quebra

| Sintoma | Causa provável |
|---|---|
| Porta 80 dá timeout, console da Oracle "certo" | `iptables` da imagem Ubuntu. Rode o `provision.sh` de novo. |
| `502 Bad Gateway` | PHP-FPM caiu. `systemctl status php8.4-fpm` e `free -h` — se a swap estiver cheia, foi memória. |
| `attempt to write a readonly database` | O `-wal` e o `-shm` são criados pelo PHP-FPM como `www-data`, e o setgid do diretório só herda o grupo, não o dono. Rode `id -nG` — se o usuário de deploy não estiver em `www-data`, ele só tem leitura neles. O `provision.sh` o adiciona ao grupo, mas **grupo novo só vale em sessão nova**: saia e entre no SSH. O `database.sqlite` estar gravável não descarta esse caso. |
| Deploy passa e o código não muda | Falta `systemctl reload php8.4-fpm`. Com `opcache.validate_timestamps=0` o PHP não percebe arquivo novo. |
| `chmod: ... Operation not permitted` no deploy | Arquivo criado pelo `www-data` (o log do dia, via cron do scheduler). `chmod` exige ser dono — por isso o `deploy.sh` usa `sudo`. Se você removeu o `sudo`, o deploy morre aí e o FPM não recarrega. |
| `key:generate` diz que falta `vendor/autoload.php` | Ordem invertida na primeira instalação: o `composer install` vem antes. Ver "Primeira vez", passo 4. |
| "Esqueci minha senha" não gera link nenhum no log | `MAIL_MAILER=log` grava em nível DEBUG e `LOG_LEVEL=warning` descarta. Nada quebrou. Ver a receita em `infra/env.production.example`. |
| `env()` devolve `null` em produção | `config:cache` já rodou. Fora de `config/`, use `config()`, nunca `env()`. |
| Login não persiste | `SESSION_DOMAIN` preenchido. Em origem única ele fica vazio. |
| Certbot falha | DNS ainda não propagou. `dig +short SEU_DOMINIO` e tente de novo. |
| Rota nova do servidor devolve HTML da SPA | Ela não está na lista de caminhos do nginx. Ver ADR-020. |
