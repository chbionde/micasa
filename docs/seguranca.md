# Varredura de segurança — MiCasa

**Data da varredura:** 12/08/2026 · **Issue:** #43 · **Alvo:** commit `f0db20c` e a produção em
`micasa-bionde.duckdns.org`

Este documento é o resultado da primeira varredura, e a linha de base da próxima. Ele registra
**o que foi medido e como**, não impressões. A regra usada em toda linha aqui: uma afirmação
sobre o estado do sistema ou tem um comando/teste por trás, ou não entra.

## Como ler

| Marca | Significa |
|---|---|
| ✅ | Confirmado seguro, com o teste ou a medição que prova — ou achado já corrigido |
| ❌ | Achado em aberto, com severidade |
| ℹ️ | Observação sem risco direto — desvio de padrão ou informação de contexto |

Cada achado guarda a **descrição original, no tempo presente em que foi escrita**, e ganha um
bloco **Situação** embaixo quando é fechado. O histórico de por que a decisão foi tomada vale
mais do que um texto reescrito para parecer que sempre esteve certo.

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

13 achados. **12 fechados**; apenas a rampa de HSTS (A9) segue em sub-issue.

- **A1 a A7** — bloco de conta e sessão. Era o único conjunto não observável de fora, e por isso
  o único cuja publicação valia esperar a correção.
- **A11 e A12** — auditoria no CI e validação em FormRequest.
- **A13** — corrigido, mas não do jeito que o achado sugeria. Ver a análise no item.
- **A8 e A10** — corrigidos nas sub-issues #53 e #55, respectivamente.
- **A9** — em rampa na sub-issue #54, porque HSTS precisa começar com prazo curto antes de
  prender os navegadores por 30 dias.

### ✅ A1 — Trocar o e-mail não exigia a senha atual · **Alta** — CORRIGIDO

`PATCH /api/user/profile` aceita um e-mail novo com a sessão apenas. `UpdateProfileRequest`
valida formato e unicidade, e mais nada.

A consequência não é o roubo da sessão — é o que ele passa a valer. A cadeia, provada por teste:

1. Alguém com a sessão da vítima (aparelho emprestado, cookie roubado) troca o e-mail da conta.
2. A vítima percebe e troca a senha pelo aparelho dela.
3. **A conta continua com o e-mail do atacante.** O "esqueci minha senha" agora entrega o
   controle a ele, e a vítima não tem como provar que a conta era dela.

Um acesso temporário vira posse permanente. É isso que a exigência de senha na troca de e-mail
existe para cortar.

**Situação:** Corrigido nesta issue. `UpdateProfileRequest` passou a exigir `current_password` quando o
e-mail muda — e só quando muda, para trocar o nome não virar cerimônia. Na tela de conta o
campo aparece junto com a mudança, explicando o motivo. Regressão em
`tests/Feature/Security/ContaESessaoTest.php`, incluindo a cadeia inteira.

O que **não** entrou: avisar o endereço antigo por e-mail. Depende da #48 (SMTP não entrega
nada em produção) e seria aviso que não chega.

### ✅ A2 — Trocar a senha não derrubava as outras sessões · **Alta** — CORRIGIDO

`AccountController::updatePassword` regenera **a sessão de quem pediu**. As demais continuam
válidas: o middleware `AuthenticateSession` do Laravel, que amarra a sessão ao hash da senha,
não está registrado em `bootstrap/app.php`.

Medido: uma linha em `sessions` do mesmo usuário sobrevive intacta à troca de senha.

Isoladamente é ruim; junto com A1, é o que impede a vítima de retomar a conta. "Trocar a senha"
é a primeira coisa que qualquer pessoa faz ao desconfiar de invasão, e aqui ela não expulsa
ninguém.

**Situação:** Corrigido nesta issue pela action `ForgetSessions`, que apaga as linhas de `sessions` do
usuário e preserva a de quem pediu a troca.

A alternativa considerada era registrar o middleware `AuthenticateSession`. Ficou de fora: ele
muda a semântica de sessão do app inteiro e cobra uma consulta por requisição para resolver o
mesmo problema que três linhas resolvem, num projeto cujo driver de sessão já é o banco.
A dependência de `SESSION_DRIVER=database` está anotada na própria action.

### ✅ A3 — A política de senha era `min:8` e nada mais · **Alta** — CORRIGIDO

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

O `uncompromised()` consulta o Have I Been Pwned por k-anonimato — sai só o prefixo de 5
caracteres do SHA-1, nunca a senha.

**Situação:** Corrigido nesta issue: `Password::defaults()` passou a ser `min(10)` mais `uncompromised()`.

**Comprimento em vez de composição** — exigir maiúscula, número e símbolo empurra a pessoa para
`Senha@123`, longa o bastante para o formulário e curta o bastante para o dicionário de ataque;
o NIST 800-63B desaconselha composição obrigatória desde 2017.

**O `uncompromised()` do Laravel foi trocado por regra própria** (`App\Rules\SenhaNaoVazada`),
porque o do framework **falha aberto**: em `NotPwnedVerifier::search()`, exceção de rede vira
`report($e)` e corpo vazio, e corpo vazio significa "nenhum vazamento encontrado" — a senha
passa, a verificação some, e o log é o único vestígio.

A regra própria **falha fechado**: verificador inalcançável recusa a senha, com mensagem que
diz que o problema é a verificação e não a senha. O custo está declarado: com o Have I Been
Pwned fora do ar, ninguém cadastra nem troca senha até a API voltar. Medido em 12/08/2026, a
API responde em 0,2–0,3 s; o timeout é 5 s com duas tentativas.

⚠️ **Correção de rota registrada:** a primeira versão desta correção mantinha o
`uncompromised()` e apenas reduzia o timeout de 30 s para 3 s, com a justificativa de que "se
vai passar, que passe rápido". A justificativa era falsa. Com falha aberta, encurtar o timeout
converte *mais* respostas lentas em aprovações silenciosas — era troca de segurança por
desempenho, exatamente o que o projeto não faz. O dev apontou; o eixo do conserto passou a ser
o modo de falha, não o tempo.

⚠️ A política vale para senha nova. **As senhas já existentes continuam como estavam** — se
alguma das 3 contas de teste usa senha fraca, ela segue fraca até ser trocada.

### ✅ A4 — Sem limite de tentativas nas rotas de conta · **Média** — CORRIGIDO

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

**Situação:** Corrigido nesta issue. Nenhuma rota autenticada ficou sem teto: `conta-sensivel` (6/min por
conta, 20/min por IP) nas três que conferem senha, `conta-leitura` (60/min) nas de leitura e
troca de contexto.

### ✅ A5 — `/login` limitava por e-mail+IP, o que não barra password spraying · **Média** — CORRIGIDO

A chave do limitador é `email|ip` (`LoginRequest::throttleKey`). Ela protege bem uma conta
específica, e não protege nada contra o ataque inverso: **uma senha comum contra muitas contas**.

Medido: 20 e-mails distintos, uma tentativa em cada, mesma origem — nenhum 429.

Combina com A3: senha fraca permitida e spraying sem barreira são o mesmo ataque visto de dois
lados. Há ainda um custo de máquina — cada tentativa é um bcrypt na VPS de 1 GB com 1/8 de OCPU.

**Situação:** Corrigido nesta issue com um limitador de rota por IP (10/min), somado ao limite por e-mail+IP
que já existia. As duas camadas não se confundem porque agem em ordens de grandeza diferentes:
quem erra a própria senha esbarra no limite por e-mail muito antes das 10 requisições.

### ✅ A6 — `PATCH /api/user/profile` enumerava e-mails sem limite · **Média** — CORRIGIDO

`Rule::unique('users')` responde 422 quando o e-mail pertence a outra pessoa e 200 quando não
pertence. Sem rate limit (ver A4), isso é um oráculo: dá para descobrir quem tem conta no
sistema testando endereços, na velocidade que a rede aguentar.

`POST /register` vaza a mesma informação, mas com `throttle:6,1` na frente.

ℹ️ Enumeração por unicidade de e-mail é um custo conhecido de qualquer cadastro que impeça
e-mail duplicado — a escolha aqui é **quanto** custa explorá-la, não se ela existe.

**Situação:** Corrigido junto de A4 — a rota entrou no `conta-sensivel`. **O vazamento em si continua e não
tem conserto**: impedir e-mail duplicado e esconder se um e-mail existe são objetivos
incompatíveis. O que mudou é o custo de explorá-lo, que era zero.

### ✅ A7 — Apagar a conta deixava sessão e token de redefinição para trás · **Baixa** — CORRIGIDO

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

**Situação:** Corrigido nesta issue: `DeleteAccount` passou a apagar as sessões e o token de redefinição
pendente, dentro da mesma transação que apaga a conta.

### ✅ A8 — Sem `Content-Security-Policy` · **Média** — CORRIGIDO

Medido nos cabeçalhos reais de produção em 12/08/2026. Presentes: `X-Content-Type-Options`,
`X-Frame-Options: DENY`, `Referrer-Policy`. Ausente: CSP.

É defesa em profundidade, e a honestidade manda dizer por quê: o React escapa texto por padrão,
não há `dangerouslySetInnerHTML` em lugar nenhum do `web/src`, e não há nada em `localStorage`.
Não há XSS conhecido para a CSP conter. Ela vale como a rede embaixo do trapézio — o dia em que
um XSS aparecer é tarde para instalá-la.

**Situação:** corrigido na #53. Em 13/08/2026, depois da validação em `Report-Only`, a política
estrita foi aplicada e medida em HTML, asset e API. Cada resposta trouxe exatamente uma CSP
bloqueante e nenhuma política de relato. Scripts, estilos, imagens, fontes e conexões estão
restritos à própria origem; plugins, `<base>` e enquadramento estão bloqueados.

### ⏳ A9 — Sem `Strict-Transport-Security` · **Baixa** — RAMPA EM ANDAMENTO

Ausente, medido. O redirecionamento 80 → 443 existe e responde 301, mas ele só age **depois** de
uma requisição em texto claro já ter saído. HSTS fecha essa primeira janela.

Merece cuidado, não pressa: HSTS prende o domínio em HTTPS pelo prazo do `max-age`, para todo
navegador que já visitou. Começar com `max-age` curto é o caminho.

**Situação:** a primeira etapa da #54 versiona `max-age=300`, sem `includeSubDomains` ou
`preload`. Ela só estará ativa depois do merge e da aplicação manual pelo
`infra/aplicar-nginx.sh`. Após alguns dias estáveis, uma segunda alteração sobe para 30 dias.

### ✅ A10 — A chave de deploy tinha poder de root na VPS · **Média** — CORRIGIDO

Antes da #55, `infra/provision.sh` definia `DEPLOY_USER="${SUDO_USER:-ubuntu}"` e **não criava
usuário dedicado nem escrevia regra de sudoers restrita**. A imagem Ubuntu da Oracle entrega
`ubuntu` com `NOPASSWD:ALL`.

Que o sudo é sem senha não é suposição: `infra/deploy.sh` roda `sudo chmod` e `sudo systemctl`
e a Action o invoca com `BatchMode=yes`, sem tty. Se houvesse prompt de senha, todo deploy
falharia — e os deploys passam.

**Situação:** corrigido na #55. A Action entra como `micasa-deploy`; o sudo permite somente o
wrapper `/usr/local/sbin/micasa-pos-deploy`, sem argumentos e sem escrita pela conta. A chave
foi rotacionada, um deploy completo passou pela conta restrita e a chave antiga foi removida do
`authorized_keys` de `ubuntu` em 13/08/2026.

Isso reduz o pior caso de "root na máquina" para "controle da aplicação". Não elimina o
segundo — a chave abre um shell e o `deploy.sh` faz `git pull`. É mitigação real, não conserto.

### ✅ A11 — `composer audit` e `npm audit` não rodavam no CI · **Baixa** — CORRIGIDO

Estado hoje, medido em 12/08/2026: **0 vulnerabilidades** dos dois lados. O risco não é o
presente, é o silêncio no dia em que aparecer um aviso.

O bloqueio registrado na issue #43 — filtro de `paths:` e dois jobs chamados `quality` —
**deixou de existir** com o merge da #49. O item está liberado.

**Situação:** corrigido. Um passo de auditoria em cada workflow.

**Troca consciente, declarada:** o `composer audit` **não tem corte por severidade** — reprova
com qualquer aviso. Um aviso publicado de madrugada deixa o CI vermelho numa PR que não tem
nada a ver com dependência. Aceito de propósito: num projeto de 5 h por semana, alerta que não
bloqueia é alerta que se ignora. Já o `npm audit` roda com corte em `high`, porque a árvore do
front é ordens de grandeza maior e quase todo aviso moderado dela cai em pacote de build que
nunca vê entrada de usuário — sem o corte, o CI ficaria vermelho por ruído, que é o mesmo
fracasso pelo outro lado.

Isto **não substitui o Dependabot**, que já estava ligado. Ele avisa depois que a dependência
entrou; o CI pega antes, no momento em que ela entraria. Mesma base de avisos, momentos
diferentes.

### ✅ A12 — Validação inline no `PasswordResetController` — CORRIGIDO

`sendLink` e `reset` usam `$request->validate([...])` direto. O checklist do Revisor de Código
no `prompt-casa-os.md` pede validação em FormRequest, e todo o resto do projeto obedece. Desvio
de padrão, sem risco associado.

**Situação:** corrigido, com `SendResetLinkRequest` e `ResetPasswordRequest`. Valor de segurança:
**zero** — é consistência, para a validação morar sempre no mesmo lugar. O único comportamento
novo é um teto de tamanho em `email` e `token`, que não existia; ganhou teste.

### ✅ A13 — `switchActive` distinguia casa inexistente de casa alheia — CORRIGIDO

Medido antes da correção: casa que existe mas não é minha respondia `"Você não faz parte desta
casa."`; id inexistente respondia `"O valor selecionado para o campo casa é inválido."`. Ambos
422. A diferença permitia a um usuário autenticado descobrir quais ids de casa existem — não o
nome, não quem mora, não o conteúdo; só o tamanho do sistema.

**Situação:** corrigido, mas **não** do jeito que o achado sugeria.

A correção óbvia seria usar `"Você não faz parte desta casa"` nos dois casos. Isso fecharia a
enumeração **mentindo**: quem tivesse um id de casa velho, de uma casa já apagada, leria que
não faz parte de algo que não existe, e sairia atrás de quem administra o nada. Trocar mensagem
verdadeira por falsa para esconder um número é segurança de fachada paga em confusão de usuário
real.

A escolha não era binária. A mensagem única passou a ser:

> Não foi possível ativar esta casa. Atualize a página para ver suas casas atuais.

Verdadeira nos dois casos, idêntica nos dois casos, e diz o que fazer — que no caso legítimo
(cliente com lista de casas velha) é exatamente o certo.

O texto mora numa constante em `SwitchActiveHousehold::INDISPONIVEL`, usada tanto pela action
quanto pela mensagem do `exists` no FormRequest. Duas cópias do mesmo texto envelheceriam em
separado, e a diferença entre elas é justamente o que reabriria o vazamento.

Dois testes em `tests/Feature/Security/VarreduraTest.php`: um compara as respostas **inteiras**
(status, chaves e mensagens) dos dois casos, e o outro garante que a mensagem não voltou a
afirmar que a casa existe.

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
