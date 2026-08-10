#!/usr/bin/env bash
#
# MiCasa — restauração de um backup cifrado.
#
#   ./infra/restaurar.sh ARQUIVO.sqlite.gz.age CHAVE_PRIVADA [DESTINO.sqlite]
#
# NÃO RODE ISTO NA VPS DE PRODUÇÃO. Duas razões, e a segunda é a que importa:
#
#   1. Restaurar por cima do banco vivo apaga o que estiver lá.
#   2. A chave privada do age não pode existir na VPS. É o desenho inteiro do
#      ADR-009 emendado: a VPS só guarda a chave PÚBLICA, para que quem domine o
#      servidor não consiga decifrar backup nenhum — nem os antigos. Trazer a
#      privada para cá, "só um minutinho para restaurar", desfaz isso.
#
# O lugar de rodar é a sua máquina, ou a segunda micro AMD reservada na emenda do
# ADR-003 justamente como alvo do teste de restauração.
#
# Como obter o arquivo: pelo console da Backblaze, ou com a chave Read Only —
# nunca com a chave da VPS, que é Write Only e não sabe ler.
#
set -Eeuo pipefail

ARQUIVO="${1:-}"
IDENTIDADE="${2:-}"
DESTINO="${3:-}"

log()  { printf '\033[1;32m==> %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

[[ -n "${ARQUIVO}" && -n "${IDENTIDADE}" ]] \
  || erro "Uso: $0 ARQUIVO.sqlite.gz.age CHAVE_PRIVADA [DESTINO.sqlite]"
[[ -f "${ARQUIVO}"    ]] || erro "Arquivo '${ARQUIVO}' não encontrado."
[[ -f "${IDENTIDADE}" ]] || erro "Chave privada '${IDENTIDADE}' não encontrada."

grep -q 'AGE-SECRET-KEY' "${IDENTIDADE}" \
  || erro "'${IDENTIDADE}' não contém uma chave privada age. A pública não decifra nada."

for prog in age gzip sqlite3; do
  command -v "${prog}" >/dev/null || erro "Falta o programa '${prog}'."
done

DESTINO="${DESTINO:-$(basename "${ARQUIVO}" .gz.age)}"
[[ -e "${DESTINO}" ]] && erro "'${DESTINO}' já existe. Escolha outro destino — não vou sobrescrever."

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

# ---------------------------------------------------------------------------
# 1. Decifrar e descomprimir
# ---------------------------------------------------------------------------
log "Decifrando"
age -d -i "${IDENTIDADE}" -o "${TMP}/banco.gz" "${ARQUIVO}" \
  || erro "age não decifrou. Chave privada errada, ou arquivo corrompido."

log "Descomprimindo"
gunzip -c "${TMP}/banco.gz" > "${TMP}/banco.sqlite" || erro "gunzip falhou: arquivo corrompido."

# ---------------------------------------------------------------------------
# 2. Provar que o que saiu é um banco usável
# ---------------------------------------------------------------------------
# Decifrar sem erro NÃO prova restauração. O critério do ADR-009 é restaurar, e
# restaurar significa abrir o banco e achar os dados lá dentro.
log "Verificando"
INTEGRIDADE="$(sqlite3 "${TMP}/banco.sqlite" 'PRAGMA integrity_check;' | head -1)"
[[ "${INTEGRIDADE}" == "ok" ]] || erro "integrity_check devolveu '${INTEGRIDADE}'. Backup inutilizável."

sqlite3 "${TMP}/banco.sqlite" 'SELECT COUNT(*) FROM users;' >/dev/null \
  || erro "Sem a tabela 'users'. Isto não é um banco do MiCasa."

mv "${TMP}/banco.sqlite" "${DESTINO}"

# ---------------------------------------------------------------------------
# 3. Mostrar o que veio dentro
# ---------------------------------------------------------------------------
# Um relatório de conteúdo, não um "OK". A pergunta que a restauração precisa
# responder não é "o arquivo abriu?", é "os dados de quando estão aqui?" — um
# backup íntegro de três meses atrás passa em todos os testes acima e mesmo assim
# significa que o cron parou em silêncio há três meses.
log "Restaurado em ${DESTINO}"
echo

# Cada tabela é contada SOZINHA, de propósito. A primeira versão disto era um
# UNION ALL único: bastava uma tabela faltar para a consulta inteira falhar e o
# relatório sumir em silêncio — perdendo justamente a informação que dá sentido à
# restauração. Tabela ausente agora APARECE, que é o dado mais importante de todos
# quando se restaura um backup antigo, de antes de uma migração.
printf '%-14s %s\n' "TABELA" "LINHAS"
for tabela in users households household_user shopping_lists shopping_list_items; do
  if linhas="$(sqlite3 "${DESTINO}" "SELECT COUNT(*) FROM ${tabela};" 2>/dev/null)"; then
    printf '%-14s %s\n' "${tabela}" "${linhas}"
  else
    printf '%-14s %s\n' "${tabela}" "AUSENTE"
  fi
done

echo
echo "Registro mais recente encontrado:"
MAIS_RECENTE=""
for tabela in users households shopping_lists; do
  quando="$(sqlite3 "${DESTINO}" "SELECT MAX(created_at) FROM ${tabela};" 2>/dev/null || true)"
  [[ -n "${quando}" && "${quando}" > "${MAIS_RECENTE}" ]] && MAIS_RECENTE="${quando}"
done

if [[ -n "${MAIS_RECENTE}" ]]; then
  echo "  ${MAIS_RECENTE} (UTC)"
  echo
  echo "Compare com a data de hoje. Um backup íntegro e VELHO passa em todas as"
  echo "verificações acima e ainda assim significa que o cron parou há semanas."
else
  echo "  nenhuma — o banco não tem registro com created_at."
  echo
  echo "Num backup de produção isto é sinal de alerta: ou o banco está vazio, ou"
  echo "não é o banco que você pensa que é."
fi
