#!/usr/bin/env bash
#
# MiCasa — cria a chave do B2 com a capacidade MÍNIMA para o backup.
#
#   ./infra/b2-criar-chave.sh
#
# RODE NA SUA MÁQUINA, NUNCA NA VPS. O script pede a Master Application Key, que
# tem acesso total à conta da Backblaze. Ela não pode passar pelo servidor — se
# passar, um invasor que domine a VPS domina a conta inteira, e aí não importa
# quão restrita é a chave que este script cria.
#
# POR QUE ELE EXISTE
#
# O console web só oferece três presets: Read Only, Write Only e Read and Write.
# Descobriu-se em 2026-08-10, exercitando a API, que o preset "Write Only" da
# Backblaze significa "tudo menos ler" — e inclui `deleteFiles` e
# `writeBucketLifecycleRules`. Ou seja: a chave que vive na VPS conseguia apagar
# todos os backups, que é exatamente o cenário de ransomware que o desenho da #4
# queria impedir. Ver a correção de fato na issue #44.
#
# A API aceita lista explícita de capacidades. É o único caminho para uma chave
# que sobe arquivo e não faz mais nada.
#
set -Eeuo pipefail

BUCKET_ID="${BUCKET_ID:-83d31c711f11e94497f40a1c}"   # micasa-backups
NOME_CHAVE="${NOME_CHAVE:-micasa-vps-backup}"
CAPACIDADES='["writeFiles"]'

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
aviso(){ printf '\033[1;33m[aviso] %s\033[0m\n' "$*"; }
erro() { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

# Guarda contra o pior engano possível com este script. /var/www é onde a
# aplicação vive na VPS; se o script está rodando de lá, a Master Key estaria
# prestes a ser digitada dentro do servidor.
case "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" in
  /var/www/*) erro "Este script está em /var/www — parece a VPS. A Master Key NUNCA entra no servidor. Rode da sua máquina." ;;
esac

command -v curl >/dev/null    || erro "Falta o curl."
command -v python3 >/dev/null || erro "Falta o python3."

campo() { python3 -c '
import sys, json
d = json.load(sys.stdin)
for c in sys.argv[1].split("."):
    d = d[c]
print(d)
' "$1"; }

cat <<'TXT'

Este script vai pedir a MASTER APPLICATION KEY da sua conta Backblaze.

  - Ela fica no console em Application Keys, no topo, separada das demais.
  - Ela NÃO vai para arquivo nenhum: é lida direto para uma variável de shell,
    sem eco na tela e sem entrar no histórico do bash.
  - Ela é usada uma única vez, para criar a chave restrita, e descartada quando
    este script termina.
  - Não a cole em chat, issue ou commit.

TXT

read -r -s -p "Master applicationKeyId: " MASTER_ID; echo
read -r -s -p "Master applicationKey:   " MASTER_KEY; echo
[[ -n "${MASTER_ID}" && -n "${MASTER_KEY}" ]] || erro "Valor vazio."

log "Autorizando"
AUTH="$(curl -sS --fail-with-body -u "${MASTER_ID}:${MASTER_KEY}" \
  https://api.backblazeb2.com/b2api/v4/b2_authorize_account)" \
  || erro "Autorização falhou. Confira os dois valores."

TOKEN="$(printf '%s' "${AUTH}"   | campo authorizationToken)"
API_URL="$(printf '%s' "${AUTH}" | campo apiInfo.storageApi.apiUrl)"
CONTA="$(printf '%s' "${AUTH}"   | campo accountId)"

# Se a chave informada não for a master, ela não tem writeKeys e o create abaixo
# falharia com uma mensagem menos clara que esta.
CAPS="$(printf '%s' "${AUTH}" | python3 -c '
import sys, json
d = json.load(sys.stdin)
for caminho in (("apiInfo","storageApi","allowed","capabilities"),("allowed","capabilities"),("apiInfo","storageApi","capabilities")):
    n = d
    try:
        for c in caminho: n = n[c]
        print(",".join(n)); break
    except (KeyError, TypeError): continue
' || true)"

case ",${CAPS}," in
  *,writeKeys,*) : ;;
  *) erro "Esta chave não tem 'writeKeys' — não é a Master Key. Só ela cria outras chaves." ;;
esac

log "Criando '${NOME_CHAVE}' com capacidades ${CAPACIDADES}, restrita ao bucket ${BUCKET_ID}"
NOVA="$(curl -sS --fail-with-body \
  -H "Authorization: ${TOKEN}" \
  -H 'Content-Type: application/json' \
  -d "{\"accountId\":\"${CONTA}\",\"keyName\":\"${NOME_CHAVE}\",\"capabilities\":${CAPACIDADES},\"bucketIds\":[\"${BUCKET_ID}\"]}" \
  "${API_URL}/b2api/v4/b2_create_key")" \
  || erro "b2_create_key falhou. A resposta acima diz o motivo."

NOVO_ID="$(printf '%s'  "${NOVA}" | campo applicationKeyId)"
NOVO_KEY="$(printf '%s' "${NOVA}" | campo applicationKey)"
CAPS_NOVA="$(printf '%s' "${NOVA}" | python3 -c 'import sys,json; print(",".join(json.load(sys.stdin)["capabilities"]))')"

unset MASTER_ID MASTER_KEY TOKEN

log "Chave criada. Capacidades concedidas: ${CAPS_NOVA}"
[[ "${CAPS_NOVA}" == "writeFiles" ]] \
  || aviso "A lista veio diferente de 'writeFiles'. Confira antes de usar."

cat <<TXT

------------------------------------------------------------------------------
COPIE ESTES DOIS VALORES PARA O api/.env DA VPS, AGORA.

O applicationKey aparece UMA ÚNICA VEZ. Fechou o terminal, acabou — só resta
apagar a chave e criar outra.

B2_KEY_ID=${NOVO_ID}
B2_APP_KEY=${NOVO_KEY}

------------------------------------------------------------------------------

Depois, na VPS:

    nano /var/www/micasa/api/.env      # substitua as duas linhas
    /var/www/micasa/infra/backup.sh --testar

A saída tem de mostrar exatamente:  Capacidades desta chave: writeFiles

Só então, no console da Backblaze, apague a chave antiga. Nesta ordem: apagar
antes de confirmar que a nova funciona deixa a produção sem backup no intervalo.

TXT
