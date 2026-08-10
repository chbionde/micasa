#!/usr/bin/env bash
#
# MiCasa — backup diário do SQLite: cópia consistente -> gzip -> age -> Backblaze B2.
#
# Implementa o ADR-009 e sua emenda de 2026-08-10 (age assimétrico). Ver issue #4.
#
# Uso:
#   ./infra/backup.sh              # backup completo
#   ./infra/backup.sh --testar     # valida credenciais e o caminho de upload,
#                                  # sem tocar no banco. Sobe 1 arquivo minúsculo.
#   ./infra/backup.sh --local DIR  # grava o arquivo cifrado em DIR, sem subir nada
#                                  # e sem exigir credencial do B2.
#
# O modo --testar existe porque o que se testa TEM de ser o mesmo código que roda.
# Um teste que exercita outro caminho não prova nada sobre este.
#
# O modo --local serve para tirar um backup à mão antes de uma migração arriscada,
# e é o que torna o par backup/restauração exercitável sem gastar upload nem
# depender de rede — o que importa num sistema cujo pecado capital é não ser testado.
#
# Por que não `cp database.sqlite`: com WAL ligado, transações confirmadas vivem no
# arquivo -wal até o checkpoint. Um `cp` pode capturar um .sqlite sem elas — e o
# resultado ABRE, parece válido, e está incompleto. A API de backup online do SQLite
# (`.backup`) é consistente sob escrita concorrente.
#
# Por que a ordem gzip -> age e não o contrário: dado cifrado é indistinguível de
# ruído e não comprime. Medido neste banco: 176 KB -> 12 KB.
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${APP_DIR}/api/.env"
BANCO="${APP_DIR}/api/database/database.sqlite"
ROTULO="micasa-backup"

MODO_TESTE=0
DIR_LOCAL=""
case "${1:-}" in
  --testar) MODO_TESTE=1 ;;
  --local)  DIR_LOCAL="${2:-}" ;;
  "")       : ;;
  *)        printf 'Uso: %s [--testar | --local DIR]\n' "$0" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------------------
# Registro
# ---------------------------------------------------------------------------
# Vai para o journal (`journalctl -t micasa-backup`) e também para a tela quando
# há terminal. Rodando por cron não há tela, e sem o logger a saída sumiria — que
# é exatamente o modo de falha silenciosa que esta issue existe para evitar.
log() {
  logger -t "${ROTULO}" -- "$*" 2>/dev/null || true
  [[ -t 1 ]] && printf '\033[1;32m==> %s\033[0m\n' "$*" || true
}

erro() {
  logger -t "${ROTULO}" -p user.err -- "FALHA: $*" 2>/dev/null || true
  printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2
  exit 1
}

# ---------------------------------------------------------------------------
# Configuração — lida do .env da aplicação
# ---------------------------------------------------------------------------
# Um único lugar para configurar. O .env já é o arquivo que não se versiona e que
# tem permissão restrita; criar um segundo arquivo de segredos duplicaria a
# superfície sem ganho.
ler_env() {
  [[ -f "${ENV_FILE}" ]] || erro "Não achei ${ENV_FILE}."
  sed -nE "s/^${1}=[\"']?([^\"']*)[\"']?[[:space:]]*\$/\1/p" "${ENV_FILE}" | tail -1
}

B2_KEY_ID="$(ler_env B2_KEY_ID)"
B2_APP_KEY="$(ler_env B2_APP_KEY)"
B2_BUCKET_ID="$(ler_env B2_BUCKET_ID)"
AGE_RECIPIENT="$(ler_env BACKUP_AGE_RECIPIENT)"
PING_URL="$(ler_env BACKUP_PING_URL)"

# O modo --local não fala com o B2, então não exige credencial. A chave pública do
# age continua obrigatória em todos os modos: backup em claro não é backup.
if [[ -z "${DIR_LOCAL}" ]]; then
  [[ -n "${B2_KEY_ID}"    ]] || erro "B2_KEY_ID vazio no .env."
  [[ -n "${B2_APP_KEY}"   ]] || erro "B2_APP_KEY vazio no .env."
  [[ -n "${B2_BUCKET_ID}" ]] || erro "B2_BUCKET_ID vazio no .env."
else
  [[ -d "${DIR_LOCAL}" ]] || erro "Diretório '${DIR_LOCAL}' não existe."
fi
[[ -n "${AGE_RECIPIENT}" ]] || erro "BACKUP_AGE_RECIPIENT vazio no .env."

# A chave pública do age é o único material criptográfico nesta máquina. Se um dia
# aparecer uma chave PRIVADA aqui, o desenho inteiro do ADR-009 emendado foi
# quebrado — e é melhor parar ruidosamente do que cifrar com ela.
[[ "${AGE_RECIPIENT}" == age1* ]] \
  || erro "BACKUP_AGE_RECIPIENT não parece uma chave pública age (deve começar com 'age1')."
grep -q 'AGE-SECRET-KEY' "${ENV_FILE}" \
  && erro "Há uma chave PRIVADA age no .env. A VPS só pode conter a pública — ver ADR-009."

for prog in sqlite3 gzip age curl sha1sum python3; do
  command -v "${prog}" >/dev/null || erro "Falta o programa '${prog}'. Rode o infra/provision.sh."
done

# ---------------------------------------------------------------------------
# Área temporária
# ---------------------------------------------------------------------------
# mktemp -d cria com modo 700. Importa: por alguns segundos existe aqui uma cópia
# do banco EM CLARO, com os dados de todas as casas.
TMP="$(mktemp -d)"
limpar() { rm -rf "${TMP}"; }
trap limpar EXIT

# ---------------------------------------------------------------------------
# Backblaze B2 — API nativa
# ---------------------------------------------------------------------------
# Escolhida em vez de rclone/aws-cli porque a chave é Write Only (só `writeFiles`):
# clientes genéricos costumam conferir o destino antes de subir, e qualquer
# List/Head falha com esta chave. Três chamadas explícitas não têm comportamento
# escondido para descobrir em produção.
json_campo() {
  python3 -c '
import sys, json
d = json.load(sys.stdin)
for chave in sys.argv[1].split("."):
    d = d[chave]
print(d)
' "$1"
}

b2_autorizar() {
  local resposta
  resposta="$(curl -sS --fail-with-body \
    -u "${B2_KEY_ID}:${B2_APP_KEY}" \
    https://api.backblazeb2.com/b2api/v4/b2_authorize_account)" \
    || erro "b2_authorize_account falhou. Credencial errada, revogada ou expirada."

  # Na v4 o token fica na raiz e a URL da API dentro de apiInfo.storageApi.
  B2_TOKEN="$(printf '%s' "${resposta}" | json_campo authorizationToken)"
  B2_API_URL="$(printf '%s' "${resposta}" | json_campo apiInfo.storageApi.apiUrl)"

  # A v4 devolve o que a chave PODE fazer. Melhor conferir aqui, uma vez, do que
  # descobrir no dia da restauração que ela não era a chave que se pensava.
  B2_CAPACIDADES="$(printf '%s' "${resposta}" \
    | python3 -c 'import sys,json; print(",".join(json.load(sys.stdin)["allowed"]["capabilities"]))')"

  [[ -n "${B2_TOKEN}" && -n "${B2_API_URL}" ]] || erro "Resposta do b2_authorize_account sem token ou apiUrl."
}

b2_enviar() {
  local arquivo="$1" nome_remoto="$2" resposta url_upload token_upload sha1 tamanho

  resposta="$(curl -sS --fail-with-body \
    -H "Authorization: ${B2_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"bucketId\":\"${B2_BUCKET_ID}\"}" \
    "${B2_API_URL}/b2api/v4/b2_get_upload_url")" \
    || erro "b2_get_upload_url falhou. A chave tem 'writeFiles' e acesso a este bucket?"

  url_upload="$(printf '%s' "${resposta}" | json_campo uploadUrl)"
  token_upload="$(printf '%s' "${resposta}" | json_campo authorizationToken)"

  sha1="$(sha1sum "${arquivo}" | cut -d' ' -f1)"
  tamanho="$(stat -c%s "${arquivo}")"

  # --data-binary faz o curl definir Content-Length: a API recusa transfer
  # encoding chunked. O nome é ASCII por construção (data ISO + sufixos), então
  # não precisa de percent-encoding — mas mudar o padrão de nome exige revisar isto.
  curl -sS --fail-with-body -o /dev/null \
    -H "Authorization: ${token_upload}" \
    -H "X-Bz-File-Name: ${nome_remoto}" \
    -H "Content-Type: application/octet-stream" \
    -H "Content-Length: ${tamanho}" \
    -H "X-Bz-Content-Sha1: ${sha1}" \
    --data-binary "@${arquivo}" \
    "${url_upload}" \
    || erro "b2_upload_file falhou para ${nome_remoto}."
}

# ---------------------------------------------------------------------------
# Modo de teste
# ---------------------------------------------------------------------------
if [[ "${MODO_TESTE}" -eq 1 ]]; then
  log "Modo de teste: validando credenciais e upload, sem tocar no banco."
  b2_autorizar
  log "Autorização OK. Capacidades desta chave: ${B2_CAPACIDADES}"

  case ",${B2_CAPACIDADES}," in
    *,writeFiles,*) : ;;
    *) erro "A chave não tem 'writeFiles'. Ela não consegue subir backup nenhum." ;;
  esac
  case ",${B2_CAPACIDADES}," in
    *,deleteFiles,*)
      log "AVISO: esta chave tem 'deleteFiles'. O desenho da #4 supõe Write Only —"
      log "       com deleteFiles, quem invadir a VPS apaga o histórico de backups." ;;
  esac

  echo "teste de upload do micasa" > "${TMP}/teste.txt"
  b2_enviar "${TMP}/teste.txt" "testes/upload-$(date -u +%Y%m%dT%H%M%SZ).txt"
  log "Upload de teste concluído. O caminho inteiro funciona."
  log "Apague o arquivo de teste pelo console da Backblaze quando quiser."
  exit 0
fi

# ---------------------------------------------------------------------------
# 1. Cópia consistente
# ---------------------------------------------------------------------------
[[ -f "${BANCO}" ]] || erro "Banco não encontrado em ${BANCO}."

CARIMBO="$(date -u +%Y%m%dT%H%M%SZ)"
COPIA="${TMP}/micasa-${CARIMBO}.sqlite"

log "Copiando o banco com a API de backup do SQLite"
sqlite3 "${BANCO}" ".backup '${COPIA}'" || erro "sqlite3 .backup falhou."

# ---------------------------------------------------------------------------
# 2. Provar que a cópia presta — antes de cifrar
# ---------------------------------------------------------------------------
# Depois de cifrado não dá mais para olhar dentro sem a chave privada, que não
# existe nesta máquina. Se a verificação não acontecer aqui, não acontece nunca.
# `integrity_check` sozinho não basta: um arquivo SQLite vazio passa nele. Contar
# linhas de uma tabela que precisa existir separa "banco íntegro" de "banco certo".
log "Verificando a cópia"
INTEGRIDADE="$(sqlite3 "${COPIA}" 'PRAGMA integrity_check;' | head -1)"
[[ "${INTEGRIDADE}" == "ok" ]] || erro "integrity_check da cópia devolveu '${INTEGRIDADE}'."

USUARIOS="$(sqlite3 "${COPIA}" 'SELECT COUNT(*) FROM users;')" \
  || erro "A cópia não tem a tabela 'users'. Isso não é o banco do MiCasa."
log "Cópia íntegra: ${USUARIOS} usuário(s)"

# ---------------------------------------------------------------------------
# 3. Comprimir e cifrar
# ---------------------------------------------------------------------------
log "Comprimindo e cifrando"
gzip -9 "${COPIA}"
age -r "${AGE_RECIPIENT}" -o "${COPIA}.gz.age" "${COPIA}.gz" || erro "age falhou ao cifrar."
rm -f "${COPIA}.gz"

ARQUIVO="${COPIA}.gz.age"
NOME_REMOTO="micasa-${CARIMBO}.sqlite.gz.age"

# O age grava um cabeçalho conhecido. Conferir custa nada e pega o caso em que o
# comando "deu certo" e produziu lixo.
head -c 21 "${ARQUIVO}" | grep -q 'age-encryption.org' \
  || erro "O arquivo cifrado não tem cabeçalho age. Não sobe."

log "Cifrado: $(stat -c%s "${ARQUIVO}") bytes"

# ---------------------------------------------------------------------------
# 4. Enviar
# ---------------------------------------------------------------------------
if [[ -n "${DIR_LOCAL}" ]]; then
  # Sai antes do vigia: um backup gravado no disco da própria máquina não é o
  # backup que o vigia observa, e avisar aqui daria por cumprido um dia que não foi.
  cp "${ARQUIVO}" "${DIR_LOCAL}/${NOME_REMOTO}"
  log "Gravado em ${DIR_LOCAL}/${NOME_REMOTO} (modo --local: nada foi enviado ao B2)"
  exit 0
fi

log "Enviando ${NOME_REMOTO} para o B2"
b2_autorizar
b2_enviar "${ARQUIVO}" "${NOME_REMOTO}"
log "Upload concluído"

# ---------------------------------------------------------------------------
# 5. Avisar que deu certo
# ---------------------------------------------------------------------------
# Interruptor de homem morto (decisão do dev, 2026-08-10): o aviso é de SUCESSO, e
# quem alarma é um observador FORA desta máquina, quando o aviso não chega. Um
# alerta enviado pelo script não detectaria o script que não rodou — que é o modo
# de falha mais provável (cron parado, VPS recuperada por ociosidade).
#
# Vazio = passo pulado, para o script rodar em desenvolvimento sem inventar
# dependência. O ping vem por último, depois do upload ter retornado sucesso:
# avisar antes transformaria o vigia num carimbo.
if [[ -n "${PING_URL}" ]]; then
  curl -sS -m 15 --retry 3 -o /dev/null "${PING_URL}" \
    && log "Vigia avisado" \
    || log "AVISO: não consegui avisar o vigia. O backup subiu; o alarme pode disparar à toa."
else
  log "BACKUP_PING_URL vazio — nenhum vigia configurado. Falha do backup passará em silêncio."
fi

log "Backup concluído: ${NOME_REMOTO}"
