#!/usr/bin/env bash
#
# MiCasa — deploy da aplicação na VPS.
#
# Roda como o usuário de deploy (não root), de dentro do repositório clonado:
#   ./infra/deploy.sh
#
# O que NÃO acontece aqui: `npm run build`. O Vite + tsc não cabem
# confortavelmente em 1 GB de RAM com 1/8 de OCPU. O build do front é feito
# fora e o conteúdo de web/dist é enviado por scp (ver infra/README.md).
#
set -Eeuo pipefail

PHP_VERSION="8.4"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Produção publica da main. A checagem existe porque o `git pull` abaixo puxa a
# branch em que o repositório estiver: bastou a VPS ter ficado na branch da PR
# para o deploy passar a publicar código que não é o da main, sem avisar.
# Sobrescreva conscientemente (BRANCH_DEPLOY=outra ./infra/deploy.sh) se precisar.
BRANCH_DEPLOY="${BRANCH_DEPLOY:-main}"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -ne 0 ]] || erro "Não rode como root: os arquivos ficariam com dono errado."
[[ -f "${APP_DIR}/api/.env" ]] \
  || erro "Falta ${APP_DIR}/api/.env — copie de infra/env.production.example e preencha."

# O .env nasce de um `cp` feito à mão e herda o umask de quem copiou — na VPS
# saiu 664, legível por qualquer usuário da máquina. Ele guarda APP_KEY e as
# credenciais do B2, então 640 é o certo: dono lê e escreve, o grupo www-data
# lê (o PHP-FPM precisa), e mais ninguém. Aplicado a cada deploy porque
# permissão que depende de alguém lembrar volta a errar sozinha.
chmod 640 "${APP_DIR}/api/.env"

# Sem participação no grupo www-data, o migrate abaixo morre com "attempt to
# write a readonly database" — porque o PHP-FPM cria database.sqlite-wal como
# www-data e este usuário só o enxerga como "outros". Vale conferir aqui, com
# mensagem útil, em vez de deixar o erro aparecer três passos adiante.
id -nG | grep -qw www-data \
  || erro "$(id -un) não está no grupo www-data. Rode o provision.sh; se ele já rodou, saia e entre de novo no SSH — grupo novo só vale em sessão nova."

cd "${APP_DIR}"

# ---------------------------------------------------------------------------
# 1. Código
# ---------------------------------------------------------------------------
log "Atualizando código"
ATUAL="$(git rev-parse --abbrev-ref HEAD)"
[[ "${ATUAL}" == "${BRANCH_DEPLOY}" ]] \
  || erro "O repositório está na branch '${ATUAL}', e o deploy publica '${BRANCH_DEPLOY}'. Rode 'git checkout ${BRANCH_DEPLOY}' — ou BRANCH_DEPLOY=${ATUAL} se for intencional."
git pull --ff-only

# ---------------------------------------------------------------------------
# 2. Dependências do PHP
# ---------------------------------------------------------------------------
# --no-dev: Pest, Larastan e Pint não vão para produção.
# --optimize-autoloader: mapa de classes pré-resolvido, menos I/O por requisição.
log "Instalando dependências do PHP"
cd "${APP_DIR}/api"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# 3. Banco
# ---------------------------------------------------------------------------
# --force porque migrate em produção pergunta confirmação e aqui não há tty.
log "Rodando migrations"
touch database/database.sqlite
php artisan migrate --force

# ---------------------------------------------------------------------------
# 4. Caches de produção
# ---------------------------------------------------------------------------
# config:cache junta todo o config/ num arquivo só — a partir daí, chamadas a
# env() fora de config/ passam a retornar null. É o pega-ratão clássico de
# primeiro deploy Laravel.
log "Gerando caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ---------------------------------------------------------------------------
# 5. Permissões de escrita
# ---------------------------------------------------------------------------
# O WAL do SQLite cria database.sqlite-wal e -shm ao lado do banco, então o
# grupo precisa de escrita no diretório, não só no arquivo.
log "Ajustando permissões"
# sudo porque quem escreve aqui não é só o usuário de deploy: o cron do
# scheduler e o PHP-FPM rodam como www-data e criam arquivos próprios —
# storage/logs/laravel-AAAA-MM-DD.log é o caso garantido, o cron cria um por dia.
#
# chmod exige ser dono do arquivo e falha com EPERM mesmo quando a permissão já
# está correta: o syscall confere o dono antes de olhar se a mudança seria
# no-op. Sem sudo, o set -e mata o deploy exatamente aqui — depois das migrations
# e ANTES do reload do FPM. Com opcache.validate_timestamps=0 isso significa
# banco migrado e código antigo no ar, com o script aparentando ter funcionado.
sudo chmod -R g+w storage bootstrap/cache database

# ---------------------------------------------------------------------------
# 6. Reinício dos processos
# ---------------------------------------------------------------------------
# reload do FPM é obrigatório: com opcache.validate_timestamps=0, o PHP não
# percebe arquivo novo sozinho. Sem isto o deploy "funciona" e nada muda.
log "Recarregando serviços"
sudo systemctl reload "php${PHP_VERSION}-fpm"
sudo systemctl restart micasa-queue

log "Deploy concluído."
echo "    Saúde:  curl -sS https://\$(grep -oP '(?<=^APP_URL=https://).*' .env)/up"
