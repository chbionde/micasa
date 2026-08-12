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

# Duas coisas diferentes, e confundi-las foi um bug:
#
#   SUDO      prefixo para ELEVAR um comando. Vazio quando já somos root —
#             `${SUDO} chmod ...` vira ` chmod ...`, que funciona.
#   SUDO_BIN  o binário do sudo, para CONSULTAR a política (`sudo -l -U`).
#             Este nunca pode ser vazio: `"${SUDO_BIN}" -n -l -U` com SUDO vazio vira
#             ` -n -l -U`, e o shell tenta executar `-n`. Root pode rodar sudo
#             (root_sudo é ligado por padrão) e não precisa se autenticar.
SUDO_BIN="$(command -v sudo || true)"
[[ -n "${SUDO_BIN}" ]] || erro "sudo não encontrado — preciso dele para conferir a política."

if [[ $EUID -eq 0 ]]; then
  SUDO=""
else
  SUDO="${SUDO_BIN}"
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
# A primeira versão disto perguntava com `sudo -u micasa-deploy sudo -l`, e
# falhava SEMPRE — mesmo com tudo configurado certo. O `sudo -l` de dentro
# rodava COMO a conta de deploy, e `sudo -l` exige que quem invoca se
# autentique. A conta não tem senha, de propósito. A autenticação era
# impossível, a saída vinha vazia, e o script acusava um erro que não existia.
#
# O jeito certo é perguntar a partir do root, que é quem este script já é:
# `sudo -l -U conta comando` consulta a política PARA outra conta, sem que ela
# precise se autenticar, e sai com 1 se o comando não for permitido (man sudo).
# O -n garante que nada fique pendurado esperando entrada.
log "Conferindo o que a conta de deploy pode fazer com sudo"

mostrar_evidencia() {
  printf '\n    ---- conteúdo de %s\n' "${REGRA_SUDO}"
  ${SUDO} cat "${REGRA_SUDO}" 2>/dev/null | sed 's/^/    /' || printf '    (não existe)\n'
  printf '\n    ---- permissões do arquivo\n'
  ${SUDO} ls -l "${REGRA_SUDO}" 2>/dev/null | sed 's/^/    /' || true
  printf '\n    ---- o que o sudo enxerga para a conta\n'
  "${SUDO_BIN}" -n -l -U "${USUARIO_DEPLOY}" 2>&1 | sed 's/^/    /' || true
  printf '\n'
}

if "${SUDO_BIN}" -n -l -U "${USUARIO_DEPLOY}" "${POS_DEPLOY}" >/dev/null 2>&1; then
  printf '  ✓ a conta pode rodar %s\n' "${POS_DEPLOY}"
else
  mostrar_evidencia
  erro "A conta não recebeu a autorização esperada — evidência acima."
fi

# Sudo irrestrito viria de outra regra ou de um grupo (sudo/admin). Se
# aparecer, o ganho da #55 é nenhum: a conta continuaria podendo tudo.
if "${SUDO_BIN}" -n -l -U "${USUARIO_DEPLOY}" 2>/dev/null | grep -qE '\(ALL(\s*:\s*ALL)?\)\s+ALL'; then
  aviso "ATENÇÃO: a conta ainda aparece com sudo IRRESTRITO."
  aviso "Procure outra regra em /etc/sudoers.d/ ou um grupo (sudo/admin) que a inclua."
  mostrar_evidencia
else
  printf '  ✓ sem sudo irrestrito\n'
fi

# O SSH da Action precisa de shell de verdade. O `adduser --system` usa
# /usr/sbin/nologin quando o --shell não pega, e o sintoma apareceria só lá na
# frente, como um deploy que conecta e não executa nada.
SHELL_ATUAL="$(getent passwd "${USUARIO_DEPLOY}" | cut -d: -f7)"
if [[ "${SHELL_ATUAL}" == */nologin || "${SHELL_ATUAL}" == */false ]]; then
  aviso "A conta estava com shell ${SHELL_ATUAL}; corrigindo para /bin/bash."
  ${SUDO} usermod -s /bin/bash "${USUARIO_DEPLOY}"
  SHELL_ATUAL="/bin/bash"
fi
printf '  ✓ shell: %s\n' "${SHELL_ATUAL}"

if id -nG "${USUARIO_DEPLOY}" | grep -qw www-data; then
  printf '  ✓ no grupo www-data\n'
else
  erro "A conta não entrou no grupo www-data — o migrate do deploy falharia."
fi

if [[ -d "/home/${USUARIO_DEPLOY}/.ssh" ]]; then
  printf '  ✓ /home/%s/.ssh existe, pronto para receber a chave\n' "${USUARIO_DEPLOY}"
else
  erro "Faltou criar /home/${USUARIO_DEPLOY}/.ssh"
fi

log "Pronto."
cat <<FIM

    A conta existe, mas ainda NÃO recebeu chave nenhuma — ninguém entra por
    ela até você autorizar uma. Os próximos passos estão no infra/README.md,
    seção "Migrando uma VPS que já existe", a partir do passo 3.

    Enquanto o secret SSH_USER do GitHub não apontar para ${USUARIO_DEPLOY},
    o deploy continua entrando pela conta antiga — e continua funcionando.

FIM
