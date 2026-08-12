# Varredura de segurança — MiCasa

**Data da varredura:** 12/08/2026 · **Issue:** #43 · **Alvo:** commit `f0db20c` e a produção em
`micasa-bionde.duckdns.org`

Este documento é o resultado da primeira varredura, e a linha de base da próxima. Ele registra
**o que foi medido e como**, não impressões. A regra usada em toda linha aqui: uma afirmação
sobre o estado do sistema ou tem um comando/teste por trás, ou não entra.

## Como ler

| Marca | Significa |
|---|---|
| ✅ | Confirmado seguro, com o teste ou a medição que prova |
| ❌ | Achado, com issue própria e severidade |
| ℹ️ | Observação sem risco direto — desvio de padrão ou informação de contexto |

**Severidade**, como usada aqui:

- **Alta** — leva a perda de controle da conta ou dos dados sem exigir posição privilegiada
- **Média** — amplia bastante o dano de outra falha, ou remove uma barreira que deveria existir
- **Baixa** — higiene, retenção de dado, defesa em profundidade

---

## 1. O que a varredura NÃO cobriu

Dito primeiro, porque a ausência é o que costuma virar falsa sensação de cobertura:

- **Nenhum teste de penetração ativo contra a produção.** Só leitura: cabeçalhos, códigos de
  resposta e uma requisição sem token CSRF. Nada de fuzzing, nada de força bruta real.
- **A configuração viva da VPS não foi relida nesta sessão** (sudoers, permissões de arquivo,
  regras de nftables). O que está afirmado sobre a VPS abaixo vem do código de provisionamento
  versionado ou de medições anteriores, e está marcado como tal.
- **Dependências transitivas do PHP/Node** foram checadas só pelos avisos públicos
  (`composer audit`, `npm audit`), que não enxergam vulnerabilidade sem CVE publicado.
- **Nada sobre o bot do Telegram**, que ainda não existe.

---

## 2. Achados

### ❌ A1 — Trocar o e-mail não exige a senha atual · **Alta**

`PATCH /api/user/profile` aceita um e-mail novo com a sessão apenas. `UpdateProfileRequest`
valida formato e unicidade, e mais nada.

A consequência não é o roubo da sessão — é o que ele passa a valer. A cadeia, provada por teste:

1. Alguém com a sessão da vítima (aparelho emprestado, cookie roubado) troca o e-mail da conta.
2. A vítima percebe e troca a senha pelo aparelho dela.
3. **A conta continua com o e-mail do atacante.** O "esqueci minha senha" agora entrega o
   controle a ele, e a vítima não tem como provar que a conta era dela.

Um acesso temporário vira posse permanente. É isso que a exigência de senha na troca de e-mail
existe para cortar.

**Correção:** exigir `current_password` quando o campo `email` mudar, e avisar o endereço antigo.

### ❌ A2 — Trocar a senha não derruba as outras sessões · **Alta**

`AccountController::updatePassword` regenera **a sessão de quem pediu**. As demais continuam
válidas: o middleware `AuthenticateSession` do Laravel, que amarra a sessão ao hash da senha,
não está registrado em `bootstrap/app.php`.

Medido: uma linha em `sessions` do mesmo usuário sobrevive intacta à troca de senha.

Isoladamente é ruim; junto com A1, é o que impede a vítima de retomar a conta. "Trocar a senha"
é a primeira coisa que qualquer pessoa faz ao desconfiar de invasão, e aqui ela não expulsa
ninguém.

**Correção:** registrar `AuthenticateSession` no grupo `web`, ou apagar as sessões do usuário
na troca de senha.

### ❌ A3 — A política de senha é `min:8` e nada mais · **Alta**

`Password::defaults()` é usado em três pontos (cadastro, troca e redefinição), mas **nunca foi
configurado** — nenhuma chamada `Password::defaults(fn () => ...)` existe no projeto. O padrão
do Laravel é o mínimo de 8 caracteres, sem nenhuma outra regra.

Medido diretamente na regra efetiva:

| Senha | Veredito do sistema hoje |
|---|---|
| `12345678` | **aceita** |
| `password` | **aceita** |
| `password123` | **aceita** |
| `1234567` | recusada (só por ter 7 caracteres) |

O cadastro é público e não há segundo fator. A senha é a única barreira da conta, e hoje ela
aceita as três primeiras entradas de qualquer lista de senhas comuns.

**Correção:** `Password::defaults(fn () => Password::min(10)->uncompromised())` no
`AppServiceProvider`. O `uncompromised()` consulta o Have I Been Pwned por k-anonimato — envia
os 5 primeiros caracteres do SHA-1, nunca a senha.

### ❌ A4 — Sem limite de tentativas em `/api/user/password` e `DELETE /api/user` · **Média**

Medido: 25 chutes seguidos de `current_password`, todos respondidos com 422, **nenhum 429**.
O mesmo em `DELETE /api/user`, que também pede a senha.

A causa é estrutural e vale registrar: a partir do Laravel 11, `throttle:api` **não** faz mais
parte do grupo `api` por padrão. As rotas que têm limite neste projeto o receberam uma a uma;
as de conta não receberam. Levantamento por `route:list`:

| Rota | Limite |
|---|---|
| `POST /login` | 5 por e-mail+IP (no `LoginRequest`) |
| `POST /register` | `throttle:6,1` |
| `POST /forgot-password`, `POST /reset-password` | `throttle:5,1` |
| `POST /api/invitations/{token}/accept` | limitador `aceite-convite` |
| Grupo `households/{household}` | `throttle:30,1` |
| `GET /api/user` · `PATCH /api/user/profile` · `PUT /api/user/password` · `DELETE /api/user` · `PUT /api/user/active-household` · `GET /api/households` | **nenhum** |

### ❌ A5 — `/login` limita por e-mail+IP, o que não barra password spraying · **Média**

A chave do limitador é `email|ip` (`LoginRequest::throttleKey`). Ela protege bem uma conta
específica, e não protege nada contra o ataque inverso: **uma senha comum contra muitas contas**.

Medido: 20 e-mails distintos, uma tentativa em cada, mesma origem — nenhum 429.

Combina com A3: senha fraca permitida e spraying sem barreira são o mesmo ataque visto de dois
lados. Há ainda um custo de máquina — cada tentativa é um bcrypt na VPS de 1 GB com 1/8 de OCPU.

**Correção:** somar um limite por IP ao limite por e-mail.

### ❌ A6 — `PATCH /api/user/profile` enumera e-mails sem limite · **Média**

`Rule::unique('users')` responde 422 quando o e-mail pertence a outra pessoa e 200 quando não
pertence. Sem rate limit (ver A4), isso é um oráculo: dá para descobrir quem tem conta no
sistema testando endereços, na velocidade que a rede aguentar.

`POST /register` vaza a mesma informação, mas com `throttle:6,1` na frente.

ℹ️ Enumeração por unicidade de e-mail é um custo conhecido de qualquer cadastro que impeça
e-mail duplicado — a escolha aqui é **quanto** custa explorá-la, não se ela existe.

### ❌ A7 — Apagar a conta deixa sessão e token de redefinição para trás · **Baixa**

Duas sobras, ambas medidas:

- A linha em `sessions` continua lá. `sessions.user_id` não tem chave estrangeira — é assim na
  migration padrão do Laravel.
- O token pendente em `password_reset_tokens` continua lá, indexado por **e-mail**.

**Sobre a severidade, com precisão:** a sessão órfã **não autentica ninguém**. Na requisição
seguinte o guard procura o usuário, não acha, e a requisição sai como anônima. Isto **não é**
desvio de autenticação. O que é: retenção de dado pessoal (IP e user agent) depois de um pedido
explícito de exclusão de conta.

O token de redefinição é o caso mais concreto dos dois: se o mesmo e-mail for cadastrado de novo
dentro da validade do token, o link antigo redefine a senha da **conta nova**.

Isto substitui a antiga anotação de "casa órfã no banco", que foi medida em 10/08/2026 e **não
existe** — são 3 casas, com 1 membro cada.

### ❌ A8 — Sem `Content-Security-Policy` · **Média**

Medido nos cabeçalhos reais de produção em 12/08/2026. Presentes: `X-Content-Type-Options`,
`X-Frame-Options: DENY`, `Referrer-Policy`. Ausente: CSP.

É defesa em profundidade, e a honestidade manda dizer por quê: o React escapa texto por padrão,
não há `dangerouslySetInnerHTML` em lugar nenhum do `web/src`, e não há nada em `localStorage`.
Não há XSS conhecido para a CSP conter. Ela vale como a rede embaixo do trapézio — o dia em que
um XSS aparecer é tarde para instalá-la.

### ❌ A9 — Sem `Strict-Transport-Security` · **Baixa**

Ausente, medido. O redirecionamento 80 → 443 existe e responde 301, mas ele só age **depois** de
uma requisição em texto claro já ter saído. HSTS fecha essa primeira janela.

Merece cuidado, não pressa: HSTS prende o domínio em HTTPS pelo prazo do `max-age`, para todo
navegador que já visitou. Começar com `max-age` curto é o caminho.

### ❌ A10 — A chave de deploy tem poder de root na VPS · **Média**

`infra/provision.sh:22` define `DEPLOY_USER="${SUDO_USER:-ubuntu}"` e **não cria usuário
dedicado nem escreve regra de sudoers restrita**. A imagem Ubuntu da Oracle entrega `ubuntu` com
`NOPASSWD:ALL`.

Que o sudo é sem senha não é suposição: `infra/deploy.sh` roda `sudo chmod` e `sudo systemctl`
e a Action o invoca com `BatchMode=yes`, sem tty. Se houvesse prompt de senha, todo deploy
falharia — e os deploys passam.

Portanto: quem obtiver o secret `SSH_PRIVATE_KEY` do GitHub tem root na produção.

ℹ️ Restringir o sudo aos três comandos do `deploy.sh` reduz o pior caso de "root na máquina"
para "controle da aplicação". Não elimina o segundo — a chave abre um shell e o `deploy.sh` faz
`git pull`. É mitigação real, não conserto.

### ❌ A11 — `composer audit` e `npm audit` não rodam no CI · **Baixa**

Estado hoje, medido em 12/08/2026: **0 vulnerabilidades** dos dois lados. O risco não é o
presente, é o silêncio no dia em que aparecer um aviso.

O bloqueio registrado na issue #43 — filtro de `paths:` e dois jobs chamados `quality` —
**deixou de existir** com o merge da #49. O item está liberado.

### ℹ️ A12 — Validação inline no `PasswordResetController`

`sendLink` e `reset` usam `$request->validate([...])` direto. O checklist do Revisor de Código
no `prompt-casa-os.md` pede validação em FormRequest, e todo o resto do projeto obedece. Desvio
de padrão, sem risco associado.

### ℹ️ A13 — `switchActive` distingue casa inexistente de casa alheia

Medido: casa que existe mas não é minha responde `"Você não faz parte desta casa."`; id
inexistente responde `"O valor selecionado para o campo casa é inválido."`. Ambos 422.

Permite descobrir quais ids de casa existem. Não vaza nome, membro nem conteúdo — o que se
aprende é o tamanho do sistema.

---

## 3. Confirmados seguros

Cada linha tem prova. Onde diz "teste novo", o teste entrou nesta varredura e vive em
`api/tests/Feature/Security/VarreduraTest.php`.

### Autorização

| Item | Prova |
|---|---|
| As 14 rotas aninhadas estão sob `scopeBindings()` | Leitura integral de `api/routes/api.php` |
| O subgrupo `shopping-lists/{shopping_list}/items` herda o escopo | **Teste novo**: item da lista B não é alcançável pela URL da lista A, na mesma casa → 404 |
| Policies checam a casa **do recurso**, nunca a da URL | `ShoppingListPolicy` usa `$list->household`; `HouseholdPolicy` recebe a própria casa |
| Membro comum não se promove a admin | Teste existente (`MemberManagementTest`) |
| Membro comum não mexe em outro membro | Teste existente |
| A casa nunca fica sem administrador | Testes existentes: rebaixar e remover o último admin dão 422 |
| Não dá para ativar casa de que não sou membro | Teste existente + guarda em `SwitchActiveHousehold` |
| Rotas fora do grupo escopam sozinhas | `/api/user*` opera sobre `$request->user()`; `/api/households` filtra por `$request->user()->households()`; o aceite escopa pelo token |

### Entrada de dados

| Item | Prova |
|---|---|
| `household_id` não vem do corpo | **Teste novo** |
| `created_by`, `checked_by`, `checked_at`, `position` não vêm do corpo | **Teste novo** |
| O papel na casa não vem do corpo do aceite de convite | **Teste novo** |
| O cadastro não entra em casa alheia pelo corpo | **Teste novo** |
| Sem SQL cru com entrada do usuário | Único caso é `orderByRaw('archived_at is not null')`, literal fixo |
| Texto livre tem teto | **Teste novo**: nome de 100 000 caracteres → 422 |

### Exposição de dados

| Item | Prova |
|---|---|
| Nenhuma resposta carrega hash de senha ou `remember_token` | **Teste novo**: varre o corpo cru de 3 rotas atrás de `password`, `remember_token` e do prefixo `$2y$` |
| Recurso de outra casa responde 404, não 403 | Testes de IDOR existentes — 403 confirmaria a existência |
| Sem PII em log | A aplicação não tem uma única chamada a `Log::` ou `logger()`; o front não tem `console.*` |
| `APP_DEBUG=false` em produção | Conferido no `.env` da VPS em 10/08/2026, junto com `SESSION_SECURE_COOKIE=true` e `LOG_LEVEL=warning` |

### Autenticação e sessão

| Item | Prova |
|---|---|
| CSRF ativo em produção | `POST /login` sem `X-XSRF-TOKEN` → **419**, medido |
| Sessão regenerada no login | `AuthenticatedSessionController::store` |
| "Esqueci minha senha" não revela se o e-mail existe | Resposta única, independente do resultado |
| Convite expira | 7 dias, `expires_at` conferido em `isUsable()` |
| Convite é de uso único, inclusive sob corrida | `UPDATE` condicional em `AcceptInvitation`; teste existente simula o consumo entre leitura e escrita |
| O token do convite não é comparado em texto | O banco guarda só `sha256`; a busca é pelo hash. Ataque de tempo sobre a comparação não recupera o token, porque o que se compara já é o hash |
| O token do convite só aparece uma vez | `#[Hidden(['token_hash'])]` + o claro só existe no retorno da criação |

### Front

| Item | Prova |
|---|---|
| Sem `dangerouslySetInnerHTML` | `grep` em todo o `web/src` — nenhuma ocorrência |
| Nada sensível em `localStorage`/`sessionStorage` | `grep` — nenhuma ocorrência. A autenticação é cookie de sessão |
| CORS não é permissivo | `allowed_origins` é a origem exata do front, com `supports_credentials`. Medido em produção: `Access-Control-Allow-Origin: https://micasa-bionde.duckdns.org` |

### Infraestrutura

| Item | Prova |
|---|---|
| Backup cifrado e **restauração testada com dados reais** | Issue #4 / PR #50 — `users 3`, `households 3`, `household_user 3` |
| fail2ban ativo na jail de SSH | PR #46; medido em 10/08: 931 tentativas falhas em 24 h, nenhuma com chance (`passwordauthentication no`) |
| `main` protegida contra force-push e deleção | Inclusive para admin |
| Dependabot, secret scanning e push protection ligados | API do GitHub, 10/08/2026 |
| Arquivos ocultos bloqueados no nginx | `location ~ /\.` vem antes do `location /` na ordem de regex; pedidos de `/.env` levam 403 |
| Rotas `/storage/{path}` do framework não são alcançáveis | Duas barreiras independentes: exigem URL assinada com `APP_KEY`, **e** o nginx nem as encaminha ao PHP — medido, `GET /storage/x` devolve o `index.html` da SPA e `PUT` devolve 405 |

---

## 4. Produto — o item sem dono técnico

O cadastro é **aberto ao público**. Isso foi decisão consciente para a fase de testes com amigos,
e a issue #43 registrou o gatilho de revisão: fechar **antes** do uso real pela família, não
depois. O convite da Fatia 1 já existe e resolveria.

O gatilho continua não disparado, porque o uso real ainda não começou: medido em 10/08/2026, as
3 contas são testes, com 0 listas e 0 itens.

Vale dizer com todas as letras, já que o contrário está escrito em vários lugares: **a Definition
of Done "está em produção e alguém da casa usou" não está cumprida.**

**Retenção de conta abandonada** não tem política definida. Registrado, sem dono.

---

## 5. Base para a próxima varredura

O que repetir, na ordem:

1. Rodar `api/tests/Feature/Security/VarreduraTest.php` — se algo ficar vermelho, um dos
   "confirmados seguros" acima deixou de ser verdade.
2. Refazer a medição dos cabeçalhos de produção e comparar com a seção 2.
3. `composer audit` e `npm audit` (ou olhar o CI, se A11 já tiver sido resolvido).
4. `php artisan route:list` e conferir a tabela de rate limit de A4 — rota nova nasce sem limite.
5. Reler esta seção 1: o que **não** foi coberto desta vez é onde a próxima varredura tem mais
   a ganhar.
