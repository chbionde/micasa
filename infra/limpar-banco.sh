#!/usr/bin/env bash
#
# MiCasa — apaga TODOS os dados da aplicação, deixando o banco vazio e migrado.
#
# Para quando as contas do banco são testes que perderam a validade e atrapalham
# mais do que ajudam. Não é rotina de manutenção: é uma ação destrutiva, sem
# volta, que se roda à mão e com atenção.
#
# Uso:
#   ./infra/limpar-banco.sh --conferir   # só mostra o que existe hoje. NÃO apaga.
#   ./infra/limpar-banco.sh              # apaga, depois de backup e confirmação
#
# O modo --conferir é o padrão recomendado antes de qualquer coisa: se a
# contagem não bater com o que você espera, você está na máquina errada ou o
# sistema teve uso que você não sabia. Nos dois casos, pare.
#
# O QUE ESTE SCRIPT APAGA: contas, casas, vínculos, convites, listas, itens,
# sessões e tokens de redefinição. Tudo.
#
# O QUE ELE NÃO TOCA: o .env, os backups já enviados ao B2, o código, os
# certificados. O banco continua existindo, com as tabelas criadas e vazias.
#
# ---------------------------------------------------------------------------
# Por que um backup obrigatório antes, se os dados "não têm valor"
# ---------------------------------------------------------------------------
# Porque "não têm valor" é uma avaliação feita ANTES de apagar, e a hora de
# descobrir que ela estava errada é sempre DEPOIS. O backup custa alguns
# segundos e um arquivo de 12 KB; a alternativa custa dados que não voltam.
# O arquivo sai cifrado com a chave pública do age, igual ao backup diário —
# nem quem roda este script consegue lê-lo sem a chave privada, que vive fora
# da VPS (ADR-009 emendado).
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${APP_DIR}/api/.env"
BANCO="${APP_DIR}/api/database/database.sqlite"
FRASE_CONFIRMACAO="APAGAR TUDO"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
aviso(){ printf '\033[1;33m[atenção] %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

MODO_CONFERIR=0
case "${1:-}" in
  --conferir) MODO_CONFERIR=1 ;;
  "")         : ;;
  *)          printf 'Uso: %s [--conferir]\n' "$0" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------------------
# Guardas
# ---------------------------------------------------------------------------
# Mesma razão do deploy.sh: como root, os arquivos que o artisan recriar ficam
# com dono errado e o PHP-FPM perde a escrita.
[[ $EUID -ne 0 ]] || erro "Não rode como root: os arquivos ficariam com dono errado."

[[ -f "${ENV_FILE}" ]] || erro "Não achei ${ENV_FILE}. Você está na máquina certa?"
[[ -f "${BANCO}"    ]] || erro "Não achei ${BANCO}."

command -v sqlite3 >/dev/null || erro "Falta o programa 'sqlite3'. Rode o infra/provision.sh."

ler_env() {
  sed -nE "s/^${1}=[\"']?([^\"']*)[\"']?[[:space:]]*\$/\1/p" "${ENV_FILE}" | tail -1
}

APP_URL="$(ler_env APP_URL)"

# ---------------------------------------------------------------------------
# Contagem
# ---------------------------------------------------------------------------
# Somente leitura. Com WAL ligado, ler durante escrita concorrente é seguro.
#
# A lista é explícita, e não um "para toda tabela", de propósito: tabela nova
# que apareça no futuro NÃO entra aqui sozinha, e a contagem passa a mentir por
# omissão. Se você acrescentou tabela, acrescente aqui também.
TABELAS=(
  users households household_user invitations
  shopping_lists shopping_list_items
  sessions password_reset_tokens
)

contar() {
  printf '\n  %-24s %s\n' "TABELA" "LINHAS"
  printf '  %-24s %s\n' "------------------------" "------"
  local total=0 n
  for t in "${TABELAS[@]}"; do
    # Tabela ausente conta como 0 em vez de derrubar o script: antes da
    # primeira migration ela realmente não existe, e isso não é erro.
    n="$(sqlite3 "${BANCO}" "select count(*) from ${t};" 2>/dev/null || echo 0)"
    printf '  %-24s %s\n' "${t}" "${n}"
    total=$(( total + n ))
  done
  printf '  %-24s %s\n' "------------------------" "------"
  printf '  %-24s %s\n\n' "TOTAL" "${total}"
  LINHAS_TOTAIS="${total}"
}

log "Estado atual de ${BANCO}"
contar

if [[ ${MODO_CONFERIR} -eq 1 ]]; then
  printf 'Modo --conferir: nada foi alterado.\n'
  exit 0
fi

if [[ "${LINHAS_TOTAIS}" -eq 0 ]]; then
  log "O banco já está vazio. Nada a fazer."
  exit 0
fi

# ---------------------------------------------------------------------------
# 1. Backup obrigatório
# ---------------------------------------------------------------------------
log "Tirando um backup cifrado antes de apagar"

DIR_BACKUP="${HOME}/backups-antes-de-limpar"
mkdir -p "${DIR_BACKUP}"

# --local usa o MESMO código do backup diário, sem gastar upload nem depender
# do B2. Um caminho de backup só para esta ocasião seria um caminho que nunca
# ninguém exercita.
"${APP_DIR}/infra/backup.sh" --local "${DIR_BACKUP}"

ARQUIVO="$(ls -t "${DIR_BACKUP}"/*.age 2>/dev/null | head -1 || true)"
[[ -n "${ARQUIVO}" ]] || erro "O backup não produziu arquivo em ${DIR_BACKUP}. NADA foi apagado."

TAMANHO="$(stat -c%s "${ARQUIVO}")"
# Um .age válido deste banco tem alguns KB. Um arquivo de algumas dezenas de
# bytes é cabeçalho sem conteúdo — sinal de backup que "funcionou" vazio.
[[ "${TAMANHO}" -gt 512 ]] \
  || erro "O backup saiu com ${TAMANHO} bytes, pequeno demais para ser real. NADA foi apagado."

printf '\n  Backup: %s (%s bytes)\n' "${ARQUIVO}" "${TAMANHO}"
aviso "Este arquivo só se lê com a chave privada age, que NÃO está nesta máquina."
aviso "Para restaurar: ./infra/restaurar.sh — veja infra/README.md."

# ---------------------------------------------------------------------------
# 2. Confirmação
# ---------------------------------------------------------------------------
# Frase inteira em vez de "s/n": um "s" sai por reflexo, e este comando não tem
# desfazer.
printf '\n'
aviso "Isto vai apagar ${LINHAS_TOTAIS} linhas de ${APP_URL:-produção}, sem volta."
aviso "Todo mundo que tiver conta perde o acesso e precisa se cadastrar de novo."
printf '\nPara seguir, digite exatamente: %s\n> ' "${FRASE_CONFIRMACAO}"
read -r RESPOSTA

if [[ "${RESPOSTA}" != "${FRASE_CONFIRMACAO}" ]]; then
  log "Cancelado. NADA foi apagado."
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. A limpeza
# ---------------------------------------------------------------------------
# migrate:fresh derruba todas as tabelas e roda as migrations de novo. Escolhido
# em vez de um DELETE por tabela porque deixa o banco idêntico ao de uma
# instalação nova — sem sequência de id continuando de onde parou, e sem
# depender de a lista de tabelas acima estar completa.
log "Apagando e recriando as tabelas"
cd "${APP_DIR}/api"
php artisan migrate:fresh --force

# Mesmo motivo do deploy.sh: o WAL cria database.sqlite-wal e -shm ao lado do
# banco, e quem escreve neles não é só este usuário — o PHP-FPM e o cron do
# scheduler rodam como www-data. Sem isto, o site volta com "attempt to write a
# readonly database".
#
# O comando é LETRA POR LETRA o mesmo do deploy.sh, inclusive os alvos que este
# script não precisaria tocar. Motivo: a issue #55 vai trocar o sudo irrestrito
# do usuário de deploy por uma regra limitada aos comandos exatos do deploy.sh.
# Uma variação aqui — mesmo `chmod -R g+w database` sozinho — deixaria de casar
# com a regra e este script quebraria no dia da troca, longe de quem a fez.
log "Ajustando permissões"
if ! sudo -n chmod -R g+w storage bootstrap/cache database 2>/dev/null; then
  # -n para não pendurar esperando senha num script que já apagou o banco.
  # Isto NÃO é passo informativo: sem a permissão, o site volta sem escrita.
  printf '\n'
  erro "$(cat <<AVISO
Não consegui ajustar as permissões (sudo pediu senha ou negou).

O BANCO JÁ FOI LIMPO. Falta só isto, e sem isto o site fica sem escrita.
Rode à mão, de dentro de ${APP_DIR}/api:

    sudo chmod -R g+w storage bootstrap/cache database

Depois confira: curl -sS ${APP_URL:-\$APP_URL}/up
AVISO
)"
fi

# ---------------------------------------------------------------------------
# 4. Conferência
# ---------------------------------------------------------------------------
log "Estado depois da limpeza"
contar

if [[ "${LINHAS_TOTAIS}" -ne 0 ]]; then
  erro "Ainda há ${LINHAS_TOTAIS} linhas. Algo não funcionou — investigue antes de usar o sistema."
fi

if [[ -n "${APP_URL}" ]]; then
  log "Conferindo se o site responde"
  CODIGO="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "${APP_URL}/up" || echo 000)"
  [[ "${CODIGO}" == "200" ]] \
    || erro "/up devolveu ${CODIGO}. O banco está limpo, mas o site não está saudável."
  printf '  /up respondeu 200\n'
fi

log "Pronto. Banco vazio e migrado."
printf '    Backup do estado anterior: %s\n' "${ARQUIVO}"
printf '    Próximo passo: criar sua conta de novo em %s\n' "${APP_URL:-o site}"
printf '    A senha terá de passar na política nova: mínimo 10 caracteres e\n'
printf '    ausente das listas de vazamento conhecidas.\n\n'
