# Aprendizado 02 — Autenticação de SPA com Sanctum (cookie + CSRF)

> O que foi construído na issue #1, explicado do zero: como um site React "logado" conversa com uma API Laravel sem nunca guardar senha ou token no navegador.

---

## 1. O problema que estamos resolvendo

O front (React em `localhost:5173`) e a API (Laravel em `micasa.test` ou `localhost:8000`) são **aplicações separadas**. Quando o Carlos faz login, as próximas requisições precisam provar "sou o Carlos" — e há duas famílias de solução:

- **Token (tipo JWT):** a API devolve um código secreto e o front guarda e envia em cada requisição. Problema: onde guardar? `localStorage` é legível por qualquer JavaScript da página — um script malicioso rouba o token (ataque XSS).
- **Cookie de sessão (o que usamos):** a API devolve um cookie marcado como `HttpOnly` — o navegador o envia sozinho a cada requisição e **o JavaScript não consegue lê-lo**. O roubo por XSS morre aí.

O **Laravel Sanctum** no "modo SPA" é exatamente isso: sessão por cookie para fronts que moram em domínio próprio. É o método que a documentação do Laravel recomenda para SPAs próprias — tokens ficam para apps mobile e APIs públicas.

## 2. As três siglas que sustentam o fluxo

**CSRF (Cross-Site Request Forgery):** se o cookie viaja sozinho, um site malicioso aberto em outra aba poderia disparar `POST /logout` (ou pior) usando o seu cookie. A defesa: a API entrega um segundo valor (token CSRF) que o front precisa **ecoar num header** a cada escrita. O site malicioso não consegue ler esse valor — só a nossa SPA consegue. Por isso o fluxo de login começa com `GET /sanctum/csrf-cookie`.

**CORS (Cross-Origin Resource Sharing):** por padrão, o navegador proíbe `localhost:5173` de chamar `localhost:8000`. O arquivo `config/cors.php` diz "essa origem específica pode, inclusive com cookies" (`supports_credentials: true`). Detalhe importante: com cookies, a origem permitida **nunca** pode ser `*` — tem que ser exata. Por isso existe a variável `FRONTEND_URL`.

**Stateful domains (`SANCTUM_STATEFUL_DOMAINS`):** a lista de domínios de front que o Sanctum trata como "da casa" — só requisições vindas deles ganham autenticação por sessão. Qualquer outra origem cai no comportamento de API pura.

## 3. O fluxo completo, passo a passo

```
1. SPA:  GET  /sanctum/csrf-cookie            → recebe cookie XSRF-TOKEN
2. SPA:  POST /login {email, senha}           → envia header X-XSRF-TOKEN
3. API:  confere credenciais, cria sessão     → devolve cookie de sessão (HttpOnly), 204
4. SPA:  GET  /api/user                       → cookie viaja sozinho → 200 {dados do Carlos}
5. SPA:  POST /logout                         → sessão invalidada, 204
```

## 4. O que cada arquivo novo faz

| Arquivo | Papel |
|---|---|
| `routes/auth.php` | Declara `POST /register`, `/login`, `/logout` |
| `app/Http/Requests/Auth/RegisterRequest.php` | Validação do registro (FormRequest — regra fora do controller, checklist do projeto) |
| `app/Http/Requests/Auth/LoginRequest.php` | Validação do login **e** o rate limit de 5 tentativas |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | Cria o usuário, dispara evento `Registered`, loga |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Abre e encerra a sessão |
| `bootstrap/app.php` | Liga o modo stateful do Sanctum e o render de erros em JSON |
| `config/cors.php` | Autoriza a origem do front com credenciais |
| `lang/pt_BR/*` | Todas as mensagens de validação/auth em português |

## 5. Segurança que entrou de fábrica (e cai em entrevista)

- **Rate limit por e-mail+IP:** 5 tentativas erradas bloqueiam a 6ª — *mesmo com a senha certa* — por um tempo. A chave é o par e-mail+IP: um vizinho errando a senha dele não bloqueia você, e um atacante distribuído não consome o limite de um alvo só. Está no `LoginRequest`, com teste cobrindo os dois lados.
- **Regeneração de sessão no login:** impede *session fixation* — se alguém plantou um id de sessão no seu navegador antes do login, ele vira lixo no momento em que você autentica.
- **`Hash::make` (bcrypt):** a senha nunca é guardada — só um hash irreversível e salgado. Vazou o banco, as senhas continuam inúteis.
- **Respostas 204 (No Content):** login não devolve dados do usuário; a SPA busca `/api/user` depois. Menos superfície, contrato mais limpo.

## 6. O bug didático da entrega

O teste "nega logout sem sessão" esperava **401** e recebeu **302** (redirect para página de login — comportamento de site clássico, inútil para SPA). Causa: o Laravel só renderizava erros como JSON para rotas `api/*`, e `/logout` vive fora desse prefixo. Correção em `bootstrap/app.php`: também renderizar JSON quando a requisição **pede** JSON (`expectsJson`). Lição: teste de borda existe para pegar exatamente esse tipo de contrato quebrado — o caminho feliz passava.

## 7. Como replicar do zero

```bash
php artisan install:api                    # instala Sanctum + rotas de API
# bootstrap/app.php → $middleware->statefulApi();
php artisan config:publish cors            # publica config/cors.php p/ editar
composer require --dev laravel-lang/common # traduções prontas
php artisan lang:add pt_BR                 # publica pt_BR em lang/
# .env → APP_LOCALE=pt_BR, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS
php artisan test                           # 14 testes, incl. rate limit e bordas
```

## 8. Quão comum isso é no mercado

Sanctum é **o** pacote de auth de API do ecossistema Laravel (o Breeze/Fortify usam por baixo). O par "cookie HttpOnly vs. token no localStorage" é pergunta clássica de entrevista de front e de back; CSRF e CORS são os dois assuntos que mais derrubam candidatos júnior em teste técnico de integração SPA+API. O que foi feito aqui — à mão, sem starter kit — é exatamente o conteúdo dessas perguntas.
