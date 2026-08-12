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
# PULAR_AJUSTES_DE_HOST=1 desliga as cinco seções que só fazem sentido numa VM
# de verdade (fuso, swap, iptables, fail2ban e certbot). Existe para ensaiar o
# script no WSL antes de rodá-lo na VPS — ver infra/README.md, "Ensaio fora da VPS".
# É dívida assumida: código de produção com uma opção que só serve para teste.
#
set -Eeuo pipefail

PHP_VERSION="8.4"          # casado com .github/workflows/ci-api.yml
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_USER="${SUDO_USER:-ubuntu}"
PULAR_AJUSTES_DE_HOST="${PULAR_AJUSTES_DE_HOST:-0}"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

pular_host() { [[ "${PULAR_AJUSTES_DE_HOST}" == "1" ]]; }

# O aviso aparece duas vezes de propósito — no início e no fim. Quem rolou a
# tela durante o apt-get precisa reencontrá-lo antes de concluir que provisionou.
banner_modo_teste() {
  printf '\n'
  printf '\033[1;33m%s\033[0m\n' \
    '===============================================================' \
    ' ATENÇÃO: PULAR_AJUSTES_DE_HOST=1 — MODO DE ENSAIO' \
    '' \
    ' Fuso horário, swap, iptables, fail2ban e certbot NÃO são aplicados.' \
    ' O que sai daqui NÃO é um servidor de produção válido.' \
    ' Nunca use esta variável na VPS.' \
    '==============================================================='
  printf '\n'
}

# ---------------------------------------------------------------------------
# 0. Pré-condições
# ---------------------------------------------------------------------------
[[ $EUID -eq 0 ]] || erro "Rode com sudo."
[[ -n "${DOMINIO:-}"   ]] || erro "Informe DOMINIO=seu.dominio (ex.: micasa-bionde.duckdns.org)."
# EMAIL_TLS só alimenta o certbot. Sem certbot, exigi-lo obrigaria a inventar um
# endereço falso no ensaio — e endereço falso num script de produção vira hábito.
if ! pular_host; then
  [[ -n "${EMAIL_TLS:-}" ]] || erro "Informe EMAIL_TLS=voce@exemplo.com (avisos de expiração do certificado)."
fi
[[ -d "${APP_DIR}/api" && -d "${APP_DIR}/web" ]] \
  || erro "Não achei api/ e web/ em ${APP_DIR}. Rode o script de dentro do repositório clonado."

log "Provisionando MiCasa"
echo "    domínio:  ${DOMINIO}"
echo "    aplicação: ${APP_DIR}"
echo "    PHP:      ${PHP_VERSION}"
echo "    deploy:   ${DEPLOY_USER}"

if pular_host; then banner_modo_teste; fi

# ---------------------------------------------------------------------------
# 1. Fuso horário: UTC
# ---------------------------------------------------------------------------
# ADR-008 fixou UTC no banco. Manter o servidor também em UTC elimina uma
# classe inteira de bug: log, cron e banco passam a falar a mesma hora.
# A conversão para America/Sao_Paulo acontece na borda, dentro do Laravel.
if pular_host; then
  warn "Fuso horário: pulado (PULAR_AJUSTES_DE_HOST=1)."
else
  log "Fuso horário do servidor -> UTC"
  timedatectl set-timezone UTC
fi

# ---------------------------------------------------------------------------
# 2. Swap
# ---------------------------------------------------------------------------
# 1 GB de RAM sem swap derruba o PHP-FPM no primeiro pico. 2 GB de swap é rede
# de proteção, não memória de trabalho — daí o swappiness baixo, para o kernel
# só recorrer ao disco quando realmente faltar RAM.
if pular_host; then
  # O WSL2 gerencia o swap na própria VM; swapon aqui devolve "Operation not
  # permitted" e derrubaria o script no set -e, sem nada a ver com o código.
  warn "Swap: pulado (PULAR_AJUSTES_DE_HOST=1)."
elif [[ ! -f /swapfile ]]; then
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
if pular_host; then
  # Fora da imagem da Oracle não existem as regras DROP que esta seção corrige.
  # Rodar aqui daria um "funcionou" que não prova nada sobre a VPS.
  warn "iptables: pulado (PULAR_AJUSTES_DE_HOST=1)."
else
  log "Liberando 80/443 no iptables"
  DEBIAN_FRONTEND=noninteractive apt-get install -y iptables-persistent >/dev/null

  for porta in 80 443; do
    if ! iptables -C INPUT -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT 2>/dev/null; then
      # -I INPUT 1 insere no topo, antes da regra REJECT que fecha a cadeia.
      iptables -I INPUT 1 -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT
    fi
  done

  # O fail2ban cria chains próprias (f2b-*) em tempo de execução, com um IP
  # banido por vez. Se elas estiverem ativas quando o netfilter-persistent
  # salvar, um banimento de 1 hora vira regra permanente no disco: o boot a
  # restaura e não há mais ninguém para expirá-la — o IP fica preso para sempre.
  # Numa segunda execução deste script, o fail2ban da seção 5 já está no ar.
  F2B_ESTAVA_ATIVO=0
  if systemctl is-active --quiet fail2ban 2>/dev/null; then
    F2B_ESTAVA_ATIVO=1
    systemctl stop fail2ban
  fi

  # O código de saída é capturado em vez de deixar o set -e agir na hora: com o
  # fail2ban parado, morrer aqui deixaria o SSH sem jail nenhuma e sem ninguém
  # avisando — degradação silenciosa, que é pior que a falha barulhenta. Religa
  # primeiro, reclama depois.
  CODIGO_SAVE=0
  netfilter-persistent save >/dev/null || CODIGO_SAVE=$?

  # `if` em vez de `[[ ... ]] && systemctl start`: com set -e, a forma curta
  # mataria o script quando a condição fosse falsa, que é o caso comum.
  if [[ "${F2B_ESTAVA_ATIVO}" -eq 1 ]]; then
    systemctl start fail2ban
  fi

  [[ "${CODIGO_SAVE}" -eq 0 ]] \
    || erro "netfilter-persistent save falhou (código ${CODIGO_SAVE}). O fail2ban foi religado."
fi

# ---------------------------------------------------------------------------
# 4. Pacotes
# ---------------------------------------------------------------------------
# `age` alimenta o infra/backup.sh (ADR-009 e sua emenda de 2026-08-10). Vem do
# repositório do Ubuntu 24.04 — não precisa de binário baixado à mão.
log "Atualizando índices e instalando pacotes base"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  software-properties-common curl git unzip sqlite3 nginx age >/dev/null

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
# 5. fail2ban (SSH)
# ---------------------------------------------------------------------------
# Medido em 2026-08-10 nesta VPS: 931 tentativas de autenticação SSH falhas em
# 24 h, vindas de 26 IPs. Nenhuma tinha chance — `sshd -T` confirma
# `passwordauthentication no`, só entra chave. Portanto isto NÃO tapa buraco
# explorável: o ganho é ruído de log, CPU e banda em 1/8 de OCPU, mais rede de
# proteção caso a autenticação por senha volte a ser ligada um dia. Ver #45.
#
# ignoreip fica sem o IP do dev DE PROPÓSITO: este repositório é público, e IP
# residencial publicado é exposição sem ganho — além de ser dinâmico e envelhecer.
if pular_host; then
  # A jail age sobre o iptables real; fora da imagem da Oracle não há regra a
  # inserir, e o serviço subiria falhando. Mesmo tratamento da seção 3.
  warn "fail2ban: pulado (PULAR_AJUSTES_DE_HOST=1)."
else
  log "Instalando e configurando fail2ban"

  # A configuração é escrita ANTES do apt-get, de propósito. O pacote sobe o
  # serviço já na instalação: sem este arquivo no lugar, o fail2ban nasce com o
  # bantime padrão de 600 s e o primeiro IP banido de uma VPS nova fica 10 min em
  # vez de 60. O `systemctl restart` seguinte NÃO corrige — o bantime é gravado
  # por banimento no banco do próprio fail2ban, e a restauração preserva o valor
  # antigo. Observado na VPS em 2026-08-10, ver #45.
  mkdir -p /etc/fail2ban/jail.d

  # jail.d/*.local é lido DEPOIS de jail.conf e de jail.d/*.conf, então este
  # arquivo vence o defaults-debian.conf que vem no pacote — sem precisar editar
  # arquivo de terceiro, que o próximo apt-get upgrade sobrescreveria.
  #
  # backend=systemd em vez do /var/log/auth.log: o journal existe sempre, e o
  # auth.log só existe enquanto o rsyslog continuar instalado.
  #
  # bantime finito de propósito. Banimento permanente transforma um engano do
  # próprio dev em visita ao console da Oracle para recuperar o acesso.
  cat > /etc/fail2ban/jail.d/micasa.local <<'EOF'
[DEFAULT]
backend  = systemd
bantime  = 1h
findtime = 10m
maxretry = 5
ignoreip = 127.0.0.1/8 ::1

[sshd]
enabled = true
EOF

  DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban >/dev/null
  systemctl enable fail2ban >/dev/null
  systemctl restart fail2ban
fi

# ---------------------------------------------------------------------------
# 6. PHP-FPM dimensionado para 1 GB
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
# 7. Permissões
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

# O setgid acima faz arquivo novo herdar o GRUPO www-data, mas não o dono. Os
# arquivos que o PHP-FPM cria — em especial database.sqlite-wal e -shm — nascem
# www-data:www-data. Sem estar no grupo, o usuário de deploy cai em "outros" e
# só tem leitura neles: o `php artisan migrate` do deploy.sh quebra com
# "attempt to write a readonly database", mesmo com o database.sqlite gravável
# e o diretório 2775. É problema de participação no grupo, não de bits.
if ! id -nG "${DEPLOY_USER}" | grep -qw www-data; then
  log "Adicionando ${DEPLOY_USER} ao grupo www-data"
  usermod -aG www-data "${DEPLOY_USER}"
  warn "Grupo novo só vale em sessão nova: saia e entre de novo no SSH antes do deploy.sh."
fi

# ---------------------------------------------------------------------------
# 8. nginx — origem única (ADR-020)
# ---------------------------------------------------------------------------
log "Configurando nginx para ${DOMINIO}"
mkdir -p "${APP_DIR}/web/dist"
# O nginx não sobe com root inexistente, e o dist só aparece no primeiro deploy.
[[ -f "${APP_DIR}/web/dist/index.html" ]] || \
  echo '<!doctype html><meta charset="utf-8"><title>MiCasa</title><p>Aguardando o primeiro deploy.' \
  > "${APP_DIR}/web/dist/index.html"
chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}/web/dist"

# Delegado ao aplicar-nginx.sh para haver UM lugar que sabe montar o site do
# nginx. Quando este trecho era próprio daqui, atualizar a configuração de uma
# VPS já provisionada significava rodar o provisionamento inteiro de novo, ou
# reproduzir estes comandos de cabeça — e esquecer o certbot install deixava o
# site em HTTP.
#
# --sem-tls porque numa máquina nova o certificado ainda não existe: quem cuida
# dele é a seção 9, logo abaixo.
DOMINIO="${DOMINIO}" "${APP_DIR}/infra/aplicar-nginx.sh" --sem-tls

# ---------------------------------------------------------------------------
# 9. Fila e scheduler
# ---------------------------------------------------------------------------
log "Instalando serviço da fila e cron do scheduler"
sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/systemd/micasa-queue.service" > /etc/systemd/system/micasa-queue.service

sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/cron/micasa-scheduler" > /etc/cron.d/micasa-scheduler
chmod 644 /etc/cron.d/micasa-scheduler

sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    "${APP_DIR}/infra/cron/micasa-backup" > /etc/cron.d/micasa-backup
chmod 644 /etc/cron.d/micasa-backup

systemctl daemon-reload
systemctl enable micasa-queue.service >/dev/null
# Não iniciamos agora: sem vendor/ nem .env, o worker entraria em loop de falha.

# ---------------------------------------------------------------------------
# 10. Certificado TLS
# ---------------------------------------------------------------------------
# Depende de o DNS já apontar para esta máquina: o desafio HTTP-01 do
# Let's Encrypt bate na porta 80 deste servidor, pelo nome do domínio.
if pular_host; then
  # O desafio HTTP-01 exige que o Let's Encrypt alcance esta máquina pela porta
  # 80, no nome do domínio. Atrás do NAT do WSL isso nunca resolve.
  warn "Certificado TLS: pulado (PULAR_AJUSTES_DE_HOST=1). O site fica em HTTP puro."
else
  DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx >/dev/null

  if [[ ! -d "/etc/letsencrypt/live/${DOMINIO}" ]]; then
    log "Emitindo certificado TLS"
    if ! certbot --nginx -d "${DOMINIO}" --non-interactive --agree-tos \
         -m "${EMAIL_TLS}" --redirect; then
      warn "Certbot falhou. Causa mais comum: DNS ainda não aponta para este servidor."
      warn "Confira com:  dig +short ${DOMINIO}  — e rode o provision.sh de novo."
    fi
  else
    # NÃO basta pular aqui. A seção 7 acabou de regerar o conf do nginx a partir
    # do template, e o template só tem `listen 80` — as diretivas de SSL que o
    # certbot havia escrito naquele arquivo foram junto. Pular deixaria o site
    # em HTTP puro; e como SESSION_SECURE_COOKIE=true, o cookie de sessão não
    # viaja em HTTP e ninguém consegue autenticar. Foi assim que a segunda
    # execução deste script derrubou o HTTPS da VPS em 2026-08-07.
    #
    # `certbot install` reescreve só a configuração, a partir do certificado que
    # já está no disco: não fala com o Let's Encrypt e não gasta cota de emissão.
    log "Reinstalando configuração TLS no nginx"
    certbot install --nginx --cert-name "${DOMINIO}" --redirect --non-interactive
  fi
fi

log "Provisionamento concluído."

# Sem certbot não há redirect para 443: no ensaio a saúde responde em HTTP puro.
if pular_host; then ESQUEMA=http; else ESQUEMA=https; fi

cat <<EOF

Próximos passos:
  1. Crie o .env de produção:
       cp ${APP_DIR}/infra/env.production.example ${APP_DIR}/api/.env
       nano ${APP_DIR}/api/.env
  2. Publique a aplicação:
       ${APP_DIR}/infra/deploy.sh
  3. Confira a saúde:
       curl -sS ${ESQUEMA}://${DOMINIO}/up

EOF

if pular_host; then banner_modo_teste; fi
