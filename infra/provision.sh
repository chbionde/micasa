#!/usr/bin/env bash
#
# MiCasa — provisionamento da VPS do zero.
#
# Alvo: Ubuntu 24.04 (amd64) numa VM.Standard.E2.1.Micro da Oracle Cloud
#       — 1/8 de OCPU e 1 GB de RAM. Ver ADR-003 e sua emenda de 2026-08-07.
#
# É idempotente: rodar duas vezes não quebra nada.
#
# Uso (de dentro do repositório já clonado — ver infra/README.md):
#   sudo DOMINIO=micasa-bionde.duckdns.org EMAIL_TLS=voce@exemplo.com ./infra/provision.sh
#
set -Eeuo pipefail

PHP_VERSION="8.4"          # casado com .github/workflows/ci-api.yml
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_USER="${SUDO_USER:-ubuntu}"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 0. Pré-condições
# ---------------------------------------------------------------------------
[[ $EUID -eq 0 ]] || erro "Rode com sudo."
[[ -n "${DOMINIO:-}"   ]] || erro "Informe DOMINIO=seu.dominio (ex.: micasa-bionde.duckdns.org)."
[[ -n "${EMAIL_TLS:-}" ]] || erro "Informe EMAIL_TLS=voce@exemplo.com (avisos de expiração do certificado)."
[[ -d "${APP_DIR}/api" && -d "${APP_DIR}/web" ]] \
  || erro "Não achei api/ e web/ em ${APP_DIR}. Rode o script de dentro do repositório clonado."

log "Provisionando MiCasa"
echo "    domínio:  ${DOMINIO}"
echo "    aplicação: ${APP_DIR}"
echo "    PHP:      ${PHP_VERSION}"
echo "    deploy:   ${DEPLOY_USER}"

# ---------------------------------------------------------------------------
# 1. Fuso horário: UTC
# ---------------------------------------------------------------------------
# ADR-008 fixou UTC no banco. Manter o servidor também em UTC elimina uma
# classe inteira de bug: log, cron e banco passam a falar a mesma hora.
# A conversão para America/Sao_Paulo acontece na borda, dentro do Laravel.
log "Fuso horário do servidor -> UTC"
timedatectl set-timezone UTC

# ---------------------------------------------------------------------------
# 2. Swap
# ---------------------------------------------------------------------------
# 1 GB de RAM sem swap derruba o PHP-FPM no primeiro pico. 2 GB de swap é rede
# de proteção, não memória de trabalho — daí o swappiness baixo, para o kernel
# só recorrer ao disco quando realmente faltar RAM.
if [[ ! -f /swapfile ]]; then
  log "Criando 2 GB de swap"
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-micasa-swap.conf
  sysctl -q -p /etc/sysctl.d/99-micasa-swap.conf
else
  log "Swap já existe, pulando"
fi

# ---------------------------------------------------------------------------
# 3. Firewall local (iptables)
# ---------------------------------------------------------------------------
# ARMADILHA DA ORACLE: as imagens Ubuntu da OCI vêm com regras iptables que
# descartam tudo além de SSH. Liberar 80/443 na Lista de Segurança do console
# NÃO basta — sem isto aqui, a porta 80 dá timeout com tudo "certo" na nuvem.
log "Liberando 80/443 no iptables"
DEBIAN_FRONTEND=noninteractive apt-get install -y iptables-persistent >/dev/null

for porta in 80 443; do
  if ! iptables -C INPUT -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT 2>/dev/null; then
    # -I INPUT 1 insere no topo, antes da regra REJECT que fecha a cadeia.
    iptables -I INPUT 1 -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT
  fi
done
netfilter-persistent save >/dev/null

# ---------------------------------------------------------------------------
# 4. Pacotes
# ---------------------------------------------------------------------------
log "Atualizando índices e instalando pacotes base"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  software-properties-common curl git unzip sqlite3 nginx >/dev/null

# O Ubuntu 24.04 só traz PHP 8.3; o CI roda 8.4. Produção divergindo do CI é
# bug esperando acontecer, então usamos o PPA do ondrej.
if ! ls /etc/apt/sources.list.d/ | grep -q 'ondrej.*php'; then
  log "Adicionando PPA ondrej/php"
  add-apt-repository -y ppa:ondrej/php >/dev/null
  apt-get update -qq
fi

log "Instalando PHP ${PHP_VERSION} e extensões"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  "php${PHP_VERSION}-fpm" \
  "php${PHP_VERSION}-cli" \
  "php${PHP_VERSION}-sqlite3" \
  "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" \
  "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-bcmath" \
  "php${PHP_VERSION}-intl" \
  "php${PHP_VERSION}-opcache" >/dev/null

if ! command -v composer >/dev/null; then
  log "Instalando Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------------------
# 5. PHP-FPM dimensionado para 1 GB
# ---------------------------------------------------------------------------
# O pool padrão (www) usa pm=dynamic e mantém filhos vivos à toa. Numa casa de
# 4 pessoas o tráfego é rajada curta com longos silêncios: ondemand mantém zero
# processo parado e sobe sob demanda.
log "Configurando pool do PHP-FPM"
if [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]]; then
  mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" \
     "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.desativado"
fi

cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/micasa.conf" <<EOF
[micasa]
user = www-data
group = www-data
listen = /run/php/php${PHP_VERSION}-fpm-micasa.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500

catch_workers_output = yes
EOF

cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-micasa.ini" <<'EOF'
memory_limit = 128M
expose_php = Off
upload_max_filesize = 8M
post_max_size = 8M

; validate_timestamps=0 é o ganho real do opcache em produção: o PHP para de
; conferir mtime a cada require. Exige `systemctl reload php-fpm` a cada
; deploy — o infra/deploy.sh faz isso. Sem o reload, código novo não sobe.
opcache.enable = 1
opcache.memory_consumption = 64
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
EOF

# CLI (artisan, fila, scheduler) precisa de mais folga que uma requisição web.
cat > "/etc/php/${PHP_VERSION}/cli/conf.d/99-micasa.ini" <<'EOF'
memory_limit = 256M
EOF

systemctl restart "php${PHP_VERSION}-fpm"

# ---------------------------------------------------------------------------
# 6. Permissões
# ---------------------------------------------------------------------------
# Dono é o usuário de deploy (escreve sem sudo), grupo www-data (o PHP-FPM lê).
# setgid nos diretórios de escrita para que arquivo novo nasça no grupo certo.
#
# database/ precisa de escrita no DIRETÓRIO, não só no arquivo: o modo WAL cria
# database.sqlite-wal e -shm ao lado. Permissão só no arquivo dá
# "attempt to write a readonly database" com o arquivo aparentemente correto.
log "Ajustando permissões"
chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}"
find "${APP_DIR}/api/storage" "${APP_DIR}/api/bootstrap/cache" "${APP_DIR}/api/database" \
  -type d -exec chmod 2775 {} + 2>/dev/null || true
chmod 2775 "${APP_DIR}"

# ---------------------------------------------------------------------------
# 7. nginx — origem única (ADR-020)
# ---------------------------------------------------------------------------
log "Configurando nginx para ${DOMINIO}"
mkdir -p "${APP_DIR}/web/dist"
# O nginx não sobe com root inexistente, e o dist só aparece no primeiro deploy.
[[ -f "${APP_DIR}/web/dist/index.html" ]] || \
  echo '<!doctype html><meta charset="utf-8"><title>MiCasa</title><p>Aguardando o primeiro deploy.' \
  > "${APP_DIR}/web/dist/index.html"
chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}/web/dist"

sed -e "s|__DOMINIO__|${DOMINIO}|g" \
    -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
    -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/nginx/micasa.conf.template" > /etc/nginx/sites-available/micasa

ln -sf /etc/nginx/sites-available/micasa /etc/nginx/sites-enabled/micasa
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

# ---------------------------------------------------------------------------
# 8. Fila e scheduler
# ---------------------------------------------------------------------------
log "Instalando serviço da fila e cron do scheduler"
sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/systemd/micasa-queue.service" > /etc/systemd/system/micasa-queue.service

sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/cron/micasa-scheduler" > /etc/cron.d/micasa-scheduler
chmod 644 /etc/cron.d/micasa-scheduler

systemctl daemon-reload
systemctl enable micasa-queue.service >/dev/null
# Não iniciamos agora: sem vendor/ nem .env, o worker entraria em loop de falha.

# ---------------------------------------------------------------------------
# 9. Certificado TLS
# ---------------------------------------------------------------------------
# Depende de o DNS já apontar para esta máquina: o desafio HTTP-01 do
# Let's Encrypt bate na porta 80 deste servidor, pelo nome do domínio.
if [[ ! -d "/etc/letsencrypt/live/${DOMINIO}" ]]; then
  log "Emitindo certificado TLS"
  DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx >/dev/null
  if ! certbot --nginx -d "${DOMINIO}" --non-interactive --agree-tos \
       -m "${EMAIL_TLS}" --redirect; then
    warn "Certbot falhou. Causa mais comum: DNS ainda não aponta para este servidor."
    warn "Confira com:  dig +short ${DOMINIO}  — e rode o provision.sh de novo."
  fi
else
  log "Certificado já existe, pulando"
fi

log "Provisionamento concluído."
cat <<EOF

Próximos passos:
  1. Crie o .env de produção:
       cp ${APP_DIR}/infra/env.production.example ${APP_DIR}/api/.env
       nano ${APP_DIR}/api/.env
  2. Publique a aplicação:
       ${APP_DIR}/infra/deploy.sh
  3. Confira a saúde:
       curl -sS https://${DOMINIO}/up

EOF
