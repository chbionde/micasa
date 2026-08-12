#!/usr/bin/env bash
#
# MiCasa — aplica a configuração do nginx na VPS a partir do repositório.
#
# Existe porque o deploy automático NÃO toca no nginx: ele builda o front, faz
# rsync do dist e roda o deploy.sh. Mudança em infra/nginx/ chega ao servidor
# só quando alguém aplica — e enquanto ninguém aplica, o repositório diz uma
# coisa e a produção faz outra. Foi assim que a #48 nasceu: funcionalidade
# entregue, mergeada, e quebrada por configuração que ninguém subiu.
#
# Uso, na VPS, de dentro de /var/www/micasa:
#   ./infra/aplicar-nginx.sh              # aplica e restaura o TLS
#   ./infra/aplicar-nginx.sh --sem-tls    # aplica sem mexer no certbot
#
# O --sem-tls existe para o provision.sh, que cuida do certbot no passo
# seguinte ao dele — numa máquina nova o certificado ainda nem existe.
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_VERSION="8.4"
TEMPLATE="${APP_DIR}/infra/nginx/micasa.conf.template"
SNIPPET_ORIGEM="${APP_DIR}/infra/nginx/cabecalhos-seguranca.conf"
SNIPPET_DESTINO="/etc/nginx/snippets/micasa-seguranca.conf"
SITE="/etc/nginx/sites-available/micasa"

log()   { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
aviso() { printf '\033[1;33m[atenção] %s\033[0m\n' "$*"; }
erro()  { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

COM_TLS=1
case "${1:-}" in
  --sem-tls) COM_TLS=0 ;;
  "")        : ;;
  *)         printf 'Uso: %s [--sem-tls]\n' "$0" >&2; exit 2 ;;
esac

# Roda como root pelo provision.sh e com sudo pelo dev. Os dois caminhos
# precisam funcionar, e nenhum deve pendurar esperando senha.
if [[ $EUID -eq 0 ]]; then
  SUDO=""
else
  command -v sudo >/dev/null || erro "Preciso de root ou sudo para escrever em /etc/nginx."
  SUDO="sudo"
fi

command -v nginx >/dev/null || erro "nginx não está instalado. Rode o infra/provision.sh."
[[ -f "${TEMPLATE}" ]]       || erro "Não achei ${TEMPLATE}."
[[ -f "${SNIPPET_ORIGEM}" ]] || erro "Não achei ${SNIPPET_ORIGEM}."

# ---------------------------------------------------------------------------
# Domínio
# ---------------------------------------------------------------------------
# Derivado do APP_URL do .env, e não pedido como argumento: comando com espaço
# reservado é comando que alguém cola literalmente. O provision.sh passa o seu
# por variável de ambiente, porque lá o .env pode ainda não existir.
if [[ -z "${DOMINIO:-}" ]]; then
  ENV_FILE="${APP_DIR}/api/.env"
  [[ -f "${ENV_FILE}" ]] || erro "Não achei ${ENV_FILE} e a variável DOMINIO não foi definida."
  APP_URL="$(sed -nE 's|^APP_URL=[\"'"'"']?https?://([^/\"'"'"']+).*|\1|p' "${ENV_FILE}" | tail -1)"
  [[ -n "${APP_URL}" ]] || erro "Não consegui extrair o domínio de APP_URL em ${ENV_FILE}."
  DOMINIO="${APP_URL}"
fi

log "Aplicando configuração do nginx para ${DOMINIO}"

# ---------------------------------------------------------------------------
# Rede de segurança
# ---------------------------------------------------------------------------
# O nginx continua servindo a configuração ANTIGA até o reload, então escrever
# um arquivo quebrado não derruba o site na hora. Derrubaria depois, no
# primeiro reload de outra pessoa — inclusive o do certbot na renovação
# automática, três meses adiante, longe de qualquer suspeito. Por isso o
# arquivo anterior volta se o teste falhar.
BACKUP=""
if [[ -f "${SITE}" ]]; then
  BACKUP="$(mktemp)"
  ${SUDO} cp "${SITE}" "${BACKUP}"
fi

restaurar_se_falhar() {
  if [[ -n "${BACKUP}" ]]; then
    ${SUDO} cp "${BACKUP}" "${SITE}"
    aviso "A configuração anterior foi restaurada. O site continua como estava."
  fi
  rm -f "${BACKUP}"
}

# ---------------------------------------------------------------------------
# 1. Snippet dos cabeçalhos
# ---------------------------------------------------------------------------
log "Instalando ${SNIPPET_DESTINO}"
${SUDO} mkdir -p /etc/nginx/snippets
${SUDO} install -m 644 "${SNIPPET_ORIGEM}" "${SNIPPET_DESTINO}"

# ---------------------------------------------------------------------------
# 2. Site
# ---------------------------------------------------------------------------
log "Gerando ${SITE} a partir do template"
${SUDO} tee "${SITE}" >/dev/null < <(
  sed -e "s|__DOMINIO__|${DOMINIO}|g" \
      -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
      -e "s|__APP_DIR__|${APP_DIR}|g" \
      "${TEMPLATE}"
)

${SUDO} ln -sf "${SITE}" /etc/nginx/sites-enabled/micasa
${SUDO} rm -f /etc/nginx/sites-enabled/default

# ---------------------------------------------------------------------------
# 3. TLS
# ---------------------------------------------------------------------------
# O template só declara `listen 80`. As linhas de TLS são escritas pelo certbot,
# então regenerar o arquivo APAGA a configuração HTTPS — e sem este passo o
# site voltaria em HTTP puro, sem ninguém perceber de imediato.
if [[ ${COM_TLS} -eq 1 ]]; then
  if ! command -v certbot >/dev/null; then
    aviso "certbot não encontrado: o site ficará em HTTP. Rode o infra/provision.sh."
  elif ! ${SUDO} certbot certificates 2>/dev/null | grep -q "${DOMINIO}"; then
    aviso "Não há certificado para ${DOMINIO}: o site ficará em HTTP."
  else
    log "Restaurando o TLS no arquivo recém-gerado"
    ${SUDO} certbot install --nginx --cert-name "${DOMINIO}" --redirect --non-interactive \
      || { restaurar_se_falhar; erro "O certbot falhou ao reinstalar o TLS."; }
  fi
fi

# ---------------------------------------------------------------------------
# 4. Teste e recarga
# ---------------------------------------------------------------------------
log "Testando a configuração"
${SUDO} nginx -t || { restaurar_se_falhar; erro "Configuração inválida. NADA foi recarregado."; }

log "Recarregando o nginx"
${SUDO} systemctl reload nginx

rm -f "${BACKUP}"

# ---------------------------------------------------------------------------
# 5. Conferência
# ---------------------------------------------------------------------------
log "Conferindo os cabeçalhos em produção"
CABECALHOS="$(curl -sSI --max-time 15 "https://${DOMINIO}/" 2>/dev/null || true)"

if [[ -z "${CABECALHOS}" ]]; then
  aviso "Não consegui buscar https://${DOMINIO}/ desta máquina. Confira de fora."
else
  for h in X-Content-Type-Options X-Frame-Options Referrer-Policy Content-Security-Policy; do
    if grep -qi "^${h}:" <<<"${CABECALHOS}"; then
      printf '  ✓ %s\n' "${h}"
    else
      aviso "${h} NÃO está presente — o include pode não ter pegado nesta location."
    fi
  done
fi

log "Pronto."
