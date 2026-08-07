# MiCasa — Como executar e testar

> **Documento vivo:** atualizado ao fim de cada fatia. Se algo aqui não funcionar, o documento está errado — abra uma issue `tipo:docs`.
>
> **Última atualização:** 2026-08-07 · estado: Fatias 0, 1 e 1.5 completas; Fatia 2 em andamento (listas: #33 e #34 na `main`). **Em produção desde 07/08/2026** em https://micasa-bionde.duckdns.org

---

## 1. Pré-requisitos (uma vez por máquina)

O desenvolvimento roda em **Linux** — no Windows, via WSL com Ubuntu 24.04. Mantenha o repositório no disco do Linux (`~/code/micasa`), **nunca** em `/mnt/c/...`: ali cada acesso a arquivo atravessa uma ponte entre os dois sistemas e o `composer install` leva minutos em vez de segundos.

| Ferramenta | Versão | Por que essa versão |
|---|---|---|
| Git | 2.x | |
| PHP | **8.4** | `.github/workflows/ci-api.yml`. O `composer.lock` exige `>= 8.4.1` — o 8.3 do Ubuntu não instala |
| Composer | 2.x | |
| Node.js + npm | **22** | `.github/workflows/ci-web.yml` |

O Ubuntu 24.04 só traz PHP 8.3, então o 8.4 vem do PPA do ondrej — o mesmo que o `infra/provision.sh` usa no servidor:

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-opcache
sudo update-alternatives --set php /usr/bin/php8.4

# Node 22 via nvm
nvm install 22 && nvm use 22
```

Confira: `php --version`, `composer --version`, `node --version`. As três versões precisam bater com o CI — quando divergem, quem está errado é a sua máquina. Ver [aprendizado 10](aprendizado/10-ensaio-de-producao-no-wsl.md).

## 2. Setup do zero (uma vez por clone)

```bash
git clone https://github.com/chbionde/micasa.git
cd micasa

# Back-end
cd api
composer install            # baixa as dependências PHP (vendor/)
cp .env.example .env        # configuração local (nunca versionada)
php artisan key:generate    # gera a chave de criptografia da app
php artisan migrate         # cria o banco SQLite e as tabelas
cd ..

# Front-end
cd web
npm install                 # baixa as dependências JS (node_modules/)
cd ..
```

O banco é um arquivo único (`api/database/database.sqlite`) — não há servidor de banco para subir.

## 3. Executar em desenvolvimento

Dois terminais, um para cada lado:

```bash
# Terminal 1 — API Laravel
cd api
php artisan serve           # http://localhost:8000

# Terminal 2 — SPA React
cd web
npm run dev                 # http://localhost:5173
```

Abra **http://localhost:5173** (sempre `localhost`, não `127.0.0.1` — ver §6). Crie uma conta em "Crie sua conta"; você cai no dashboard logado. O login usa cookie de sessão + CSRF (detalhes no [aprendizado 02](aprendizado/02-auth-sanctum-spa.md)).

### Roteiro para testar a Fatia 1 (casas e membros)

1. **Registre-se** — a casa é criada junto e você vira admin dela. O campo "Nome da casa" é opcional.
2. Vá em **Casa** no menu: você aparece na lista de membros como administrador.
3. Clique em **Gerar link de convite** e copie o link. Ele aparece **uma vez só** — o banco guarda apenas o hash.
4. Abra o link **numa janela anônima**, registre-se com outro e-mail e aceite o convite: a segunda conta entra na casa e passa a vê-la como ativa.
5. De volta à primeira conta, na tela **Casa**, promova a segunda pessoa a administrador ou remova-a.
6. Com a segunda conta em duas casas, o **seletor de casa ativa** aparece no cabeçalho (ele fica oculto para quem tem uma casa só).
7. Tente rebaixar o **último administrador**: a API recusa e a mensagem aparece na tela.

## 4. Rodar os testes e verificações

### Back-end (`api/`)

| Comando | O que verifica |
|---|---|
| `php artisan test` | Testes Pest (comportamento real: HTTP, banco, auth) |
| `vendor/bin/pint --test` | Formatação (só confere; sem `--test` corrige) |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Erros de tipo sem executar (Larastan nível 6) |

### Front-end (`web/`)

| Comando | O que verifica |
|---|---|
| `npm run test` | Testes Vitest + Testing Library (componentes e fluxos) |
| `npm run build` | Erros de tipo (`tsc`) + build de produção |
| `npm run lint` | oxlint (erros comuns de JS/React) |

**Rodar tudo de uma vez** (o mesmo que o CI executa), da raiz:

```bash
cd api && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G && php artisan test && cd ../web && npm run lint && npm run build && npm run test
```

## 5. O que o CI faz sozinho

Todo push/PR dispara os workflows de `.github/workflows/`:
- **CI API** — roda Pint + Larastan + Pest (só se algo em `api/**` mudou)
- **CI Web** — roda oxlint + build/tsc + Vitest (só se algo em `web/**` mudou)

PR só é mergeável com CI verde. Detalhes do fluxo de branches: [fluxo-trabalho.md](fluxo-trabalho.md).

## 6. Problemas comuns

| Sintoma | Causa e correção |
|---|---|
| Login "não pega" / 419 / CSRF mismatch | Você abriu `127.0.0.1:5173` — cookies são por host. Use `http://localhost:5173`. Confira no `api/.env`: `FRONTEND_URL=http://localhost:5173` e `SANCTUM_STATEFUL_DOMAINS=localhost:5173` |
| Erro de CORS no console do navegador | API não está de pé (terminal 1) ou `FRONTEND_URL` errada no `api/.env` |
| `Class ... not found` após puxar código novo | `composer install` (dependência nova) e/ou `php artisan migrate` (migration nova) |
| Front quebra após puxar código novo | `npm install` no `web/` |
| Mudou `.env` e nada aconteceu | `php artisan config:clear` |
| Porta 8000 ou 5173 ocupada | `php artisan serve --port=8001` (e ajuste `VITE_API_URL`) / o Vite oferece outra porta sozinho |

## 7. Produção

A VPS é uma `VM.Standard.E2.1.Micro` da Oracle Cloud (Vinhedo), com Ubuntu 24.04 e 1 GB de RAM. Runbook completo, incluindo tabela de diagnóstico: [infra/README.md](../infra/README.md). O porquê de cada decisão: [aprendizado 09](aprendizado/09-vps-oracle-e-deploy.md).

**Endereço:** `https://micasa-bionde.duckdns.org`

### Publicar

**Merge na `main` publica sozinho** — o workflow `.github/workflows/deploy.yml` builda o front, envia para a VPS e roda o `deploy.sh`. Nada a fazer à mão. Para publicar sem merge, use **Run workflow** na aba Actions.

Se precisar publicar manualmente (a Action fora do ar, por exemplo):

```bash
# front (na sua máquina — o build não cabe em 1 GB de RAM)
cd web && npm run build
scp -i ~/.ssh/id_ed25519 -r dist/* ubuntu@IP:/var/www/micasa/web/dist/

# back (na VPS)
ssh -i ~/.ssh/id_ed25519 ubuntu@IP
cd /var/www/micasa && ./infra/deploy.sh
```

O SSH continua necessário para: editar o `.env` de produção, ver log (`journalctl -u micasa-queue`), rodar comando artisan pontual, restaurar backup e mudar infraestrutura. O que a Action elimina é só o deploy rotineiro — que é o que se repete.

### Ensaiar o provisionamento sem tocar na VPS

`PULAR_AJUSTES_DE_HOST=1` desliga fuso, swap, `iptables` e certbot, e deixa o `provision.sh` rodar num WSL ou numa VM descartável. Serve para pegar erro de ordem e de permissão antes do servidor. Ver [infra/README.md](../infra/README.md#ensaio-fora-da-vps) e [aprendizado 10](aprendizado/10-ensaio-de-producao-no-wsl.md).

### Diferenças entre desenvolvimento e produção

| | Desenvolvimento | Produção |
|---|---|---|
| Origens | duas (Vite `:5173`, API `:8000`) — CORS ativo | **uma** só; nginx entrega os dois (ADR-020) |
| `baseURL` do axios | `http://localhost:8000` | vazia (caminhos relativos) |
| SQLite | modo padrão | **WAL** + `busy_timeout` |
| Fila e scheduler | `composer dev` | systemd + cron |
| Erros | `APP_DEBUG=true` | `false`, log `daily` em nível `warning` |

> A rota da tela de login é **`/entrar`**, não `/login` — `/login` pertence ao Laravel. Ver ADR-020.

## 8. Histórico deste documento

| Data | Fatia | O que mudou |
|---|---|---|
| 2026-08-06 | 0 | Versão inicial: setup, execução local (API + SPA), testes, CI, troubleshooting |
| 2026-08-06 | 1 | Roteiro de teste manual de casas, convites e membros |
| 2026-08-07 | 0 (#3) | Seção de produção: VPS Oracle, publicação e diferenças entre os ambientes |
