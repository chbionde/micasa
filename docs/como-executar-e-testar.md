# MiCasa — Como executar e testar

> **Documento vivo:** atualizado ao fim de cada fatia. Se algo aqui não funcionar, o documento está errado — abra uma issue `tipo:docs`.
>
> **Última atualização:** 2026-08-06 · estado: Fatia 0 (auth + telas de login; sem deploy ainda)

---

## 1. Pré-requisitos (uma vez por máquina)

| Ferramenta | Versão | Como instalar |
|---|---|---|
| Git | 2.x | https://git-scm.com |
| PHP + Composer | 8.4+ / 2.x | [Laravel Herd](https://herd.laravel.com) — instala os dois e configura o PATH |
| Node.js + npm | 22+ | https://nodejs.org |

Confira no terminal: `php --version`, `composer --version`, `node --version`.

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

## 7. Histórico deste documento

| Data | Fatia | O que mudou |
|---|---|---|
| 2026-08-06 | 0 | Versão inicial: setup, execução local (API + SPA), testes, CI, troubleshooting |
