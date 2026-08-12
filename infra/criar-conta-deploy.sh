#!/usr/bin/env bash
#
# MiCasa — cria a conta de deploy sem poder de root (issue #55).
#
# Uso, na VPS, de dentro de /var/www/micasa:
#   sudo ./infra/criar-conta-deploy.sh
#
# É idempotente: rodar de novo não quebra nada e não duplica nada.
#
# ---------------------------------------------------------------------------
# Por que um script próprio, e não "rode o provision.sh de novo"
# ---------------------------------------------------------------------------
# O provision.sh monta a máquina inteira: instala pacotes, configura o pool do
# PHP-FPM, mexe em swap, iptables, fail2ban e certbot. Ele é idempotente e não
# quebraria — mas mandar rodar tudo isso numa VPS no ar para criar UM usuário é
# desproporcional, e transforma um passo explicável em "roda esse script gigante
# e torce".
#
# O provision.sh chama este mesmo arquivo, então máquina nova e máquina antiga
# passam pelo mesmo código. Não há dois caminhos para divergirem.
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_VERSION="8.4"
USUARIO_DEPLOY="micasa-deploy"
POS_DEPLOY="/usr/local/sbin/micasa-pos-deploy"
REGRA_SUDO="/etc/sudoers.d/micasa-deploy"

log()   { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
aviso() { printf '\033[1;33m[atenção] %s\033[0m\n' "$*"; }
erro()  { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

if [[ $EUID -eq 0 ]]; then
  SUDO=""
else
  command -v sudo >/dev/null || erro "Preciso de root. Rode com: sudo $0"
  SUDO="sudo"
fi

[[ -f "${APP_DIR}/infra/micasa-pos-deploy.template" ]] \
  || erro "Não achei ${APP_DIR}/infra/micasa-pos-deploy.template. Rode de dentro do repositório."

# ---------------------------------------------------------------------------
# 1. A conta
# ---------------------------------------------------------------------------
if id -u "${USUARIO_DEPLOY}" >/dev/null 2>&1; then
  log "A conta ${USUARIO_DEPLOY} já existe"
else
  log "Criando a conta ${USUARIO_DEPLOY}"
  # --disabled-password: entra só por chave, nunca por senha digitada.
  # Shell de verdade porque a Action roda `ssh ... './infra/deploy.sh'`.
  ${SUDO} adduser --system --group --shell /bin/bash --disabled-password \
    --home "/home/${USUARIO_DEPLOY}" "${USUARIO_DEPLOY}" >/dev/null
fi

# Sem o grupo www-data, o `php artisan migrate` do deploy quebra com "attempt to
# write a readonly database" — o PHP-FPM cria o -wal como www-data e a conta de
# deploy cairia em "outros".
${SUDO} usermod -aG www-data "${USUARIO_DEPLOY}"
${SUDO} install -d -m 700 -o "${USUARIO_DEPLOY}" -g "${USUARIO_DEPLOY}" \
  "/home/${USUARIO_DEPLOY}/.ssh"

# ---------------------------------------------------------------------------
# 2. O script que roda como root
# ---------------------------------------------------------------------------
log "Instalando ${POS_DEPLOY}"
# root:root 0755 — a conta de deploy executa e NÃO escreve. Se escrevesse,
# reescreveria o script e teria root de volta pela porta dos fundos.
TMP_POS="$(mktemp)"
sed -e "s|__APP_DIR__|${APP_DIR}|g" \
    -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
    "${APP_DIR}/infra/micasa-pos-deploy.template" > "${TMP_POS}"
${SUDO} install -m 0755 -o root -g root "${TMP_POS}" "${POS_DEPLOY}"
rm -f "${TMP_POS}"

# ---------------------------------------------------------------------------
# 3. A regra de sudo
# ---------------------------------------------------------------------------
log "Escrevendo ${REGRA_SUDO}"
# VALIDADA ANTES DE INSTALAR, e a ordem é o ponto: um arquivo inválido em
# /etc/sudoers.d derruba o sudo de TODO mundo na máquina, inclusive o seu.
# Validar depois de instalar deixa uma janela — curta, mas real — em que
# ninguém consegue virar root para consertar.
TMP_SUDO="$(mktemp)"
cat > "${TMP_SUDO}" <<SUDOERS
# Gerado por infra/criar-conta-deploy.sh — não edite à mão (ver issue #55).
#
# Um comando, sem argumento. Uma regra listando o \`chmod\` do deploy.sh
# autorizaria o mesmo chmod em qualquer diretório com uma pasta \`storage\`,
# porque o sudoers casa a linha de comando literalmente e aqueles caminhos são
# relativos. Ver infra/micasa-pos-deploy.template.
${USUARIO_DEPLOY} ALL=(root) NOPASSWD: ${POS_DEPLOY}
SUDOERS

if ! ${SUDO} visudo -c -q -f "${TMP_SUDO}"; then
  rm -f "${TMP_SUDO}"
  erro "A regra saiu inválida. NADA foi instalado — seu sudo continua intacto."
fi

# 0440 é o modo que o sudo exige; com qualquer outro ele ignora o arquivo em
# silêncio, e a regra simplesmente não valeria.
${SUDO} install -m 0440 -o root -g root "${TMP_SUDO}" "${REGRA_SUDO}"
rm -f "${TMP_SUDO}"

# ---------------------------------------------------------------------------
# 4. Conferência
# ---------------------------------------------------------------------------
log "Conferindo o que a conta de deploy pode fazer com sudo"
PERMISSOES="$(${SUDO} -u "${USUARIO_DEPLOY}" sudo -l 2>/dev/null || true)"

if grep -q "${POS_DEPLOY}" <<<"${PERMISSOES}"; then
  printf '  ✓ autorizada em %s\n' "${POS_DEPLOY}"
else
  erro "A conta não recebeu a autorização esperada. Confira ${REGRA_SUDO}."
fi

if grep -qE '\(ALL(\s*:\s*ALL)?\)\s+ALL' <<<"${PERMISSOES}"; then
  aviso "ATENÇÃO: a conta ainda aparece com sudo IRRESTRITO. Procure outra regra"
  aviso "em /etc/sudoers.d/ ou um grupo (sudo/admin) que a inclua."
else
  printf '  ✓ sem sudo irrestrito\n'
fi

log "Pronto."
cat <<FIM

    A conta existe, mas ainda NÃO recebeu chave nenhuma — ninguém entra por
    ela até você autorizar uma. Os próximos passos estão no infra/README.md,
    seção "Migrando uma VPS que já existe", a partir do passo 3.

    Enquanto o secret SSH_USER do GitHub não apontar para ${USUARIO_DEPLOY},
    o deploy continua entrando pela conta antiga — e continua funcionando.

FIM
