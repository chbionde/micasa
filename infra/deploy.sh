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
# 5 e 6. Permissões e reinício dos processos — a parte que exige root
# ---------------------------------------------------------------------------
# Os três comandos com root vivem num script root:root instalado pelo
# criar-conta-deploy.sh, e a regra de sudo autoriza ESSE script, sem argumento
# nenhum (#55). O porquê de cada um deles está no próprio script — inclusive o
# do WAL do SQLite, que é o motivo de a escrita precisar valer no diretório e
# não só no arquivo.
#
# O motivo de não ser uma regra de sudoers listando os três comandos direto:
# o sudoers casa a linha de comando literalmente, e `chmod -R g+w storage ...`
# com caminho RELATIVO seria autorizado em qualquer diretório que tivesse uma
# pasta `storage` — bastaria um `cd` antes. Ver infra/micasa-pos-deploy.template.
log "Ajustando permissões e recarregando serviços"

POS_DEPLOY="/usr/local/sbin/micasa-pos-deploy"

if [[ -x "${POS_DEPLOY}" ]]; then
  sudo "${POS_DEPLOY}"
else
  # Caminho de transição: numa VPS que ainda não rodou o provisionamento novo,
  # o script não existe. Cai no jeito antigo para o deploy não quebrar no meio
  # — mas avisa alto, porque enquanto isto aparecer a chave da Action continua
  # com sudo irrestrito, que é o buraco da #55.
  warn_transicao="A conta de deploy ainda usa sudo irrestrito (#55 em aberto)."
  printf '\033[1;33m[aviso] %s\033[0m\n' "${warn_transicao}"
  printf '\033[1;33m[aviso] %s\033[0m\n' "Para fechar: rode  sudo ./infra/criar-conta-deploy.sh  na VPS."
  printf '\033[1;33m[aviso] %s\033[0m\n' "Passo a passo completo em infra/README.md."
  sudo chmod -R g+w storage bootstrap/cache database
  sudo systemctl reload "php${PHP_VERSION}-fpm"
  sudo systemctl restart micasa-queue
fi

log "Deploy concluído."
echo "    Saúde:  curl -sS https://\$(grep -oP '(?<=^APP_URL=https://).*' .env)/up"
