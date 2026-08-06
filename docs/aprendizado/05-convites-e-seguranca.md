# Aprendizado 05 — Convites por link, autorização em camadas e a condição de corrida

> O que foi construído na Fatia 1 (entrada de novos membros numa casa), explicado do zero: como um link que qualquer pessoa pode clicar vira acesso controlado, e o bug de concorrência que — sem a correção certa — deixaria um convite de uso único ser usado duas vezes ao mesmo tempo.

---

## 1. O fluxo do convite por link

Não existe cadastro "de convidado". O fluxo inteiro (ADR-007) é:

```
1. Admin (logado):     POST /households/{id}/invitations       → recebe o token em claro
2. Admin:               manda o link por WhatsApp/Telegram, fora do sistema
3. Convidado (logado):  POST /invitations/{token}/accept        → vira membro da casa
```

O convidado precisa **já ter conta e já estar logado** — o convite não cria usuário, só vincula um usuário existente a uma casa. Por que não convite por e-mail, como tanto sistema faz? Porque a família já resolve isso por mensagem — mandar um link no WhatsApp é o comportamento natural do público-alvo, e um convite por e-mail exigiria infraestrutura de e-mail transacional (SMTP, fila, template) que a v1 decidiu adiar (ADR-004). Menos peça em produção, mesmo resultado prático.

## 2. O token vive uma vez só: o banco guarda o hash

```php
// CreateInvitation::handle()
$token = Str::random(40);
Invitation::create(['token_hash' => self::hash($token), /* ... */]);
return ['invitation' => $invitation, 'token' => $token];
```

O banco (coluna `token_hash`) nunca guarda o token pronto para uso — guarda `hash('sha256', $token)`, uma transformação de mão única: dá para checar se um token bate com o hash, mas não dá para voltar do hash ao token. É o mesmo raciocínio de nunca guardar senha em claro (doc 02): se o banco vazar, ninguém entra em casa nenhuma com esse vazamento. A diferença prática para senha é que a senha usa `bcrypt` — deliberadamente lento, porque humano escolhe senha fraca e um atacante tenta adivinhar. Aqui não há o que adivinhar: `Str::random(40)` gera entropia alta demais para força bruta, então um hash rápido (SHA-256) já basta.

Consequência para quem usa o sistema: **o token em claro aparece uma única vez**, na resposta HTTP de quando o convite é criado (`InvitationController::store`, campo `token` fora do `data`). Fechou a aba, perdeu o link — não tem "esqueci o link", só revogar e gerar outro.

## 3. Autenticação diz quem você é; autorização diz o que você pode

**Autenticação** (`auth:sanctum` na rota) responde "quem está fazendo esta requisição" — já resolvido pelo cookie de sessão (doc 02). **Autorização** responde a pergunta seguinte, e diferente: "esta pessoa identificada pode fazer *isto*?" Um usuário autenticado pode não ter permissão nenhuma sobre a casa de outra família.

No Laravel, quem concentra essa segunda pergunta é uma **Policy** — uma classe com um método por ação, que devolve `true`/`false`:

```php
class HouseholdPolicy
{
    public function manageMembers(User $user, Household $household): bool
    {
        return $user->isAdminOf($household);
    }
}
```

O framework descobre `HouseholdPolicy` sozinho por **convenção de nome**: para autorizar uma ação sobre um `Household`, ele procura `HouseholdPolicy` (mesmo nome do model, sufixo `Policy`) — nenhum registro manual foi necessário. `$this->authorize('manageMembers', $household)` chama esse método com o usuário logado; se devolver `false`, o Laravel interrompe a requisição sozinho com **403 Forbidden**, sem o controller precisar escrever `if`.

## 4. Autorizar no FormRequest: por que o 403 precisa vir antes do 422

`store()` não chama `$this->authorize()` no controller — a checagem está em `StoreInvitationRequest::authorize()`:

```php
public function authorize(): bool
{
    $household = $this->route('household');
    return $household instanceof Household
        && $this->user()?->isAdminOf($household) === true;
}
```

O Laravel resolve o `FormRequest` **antes** de o corpo do método `store()` rodar, e dentro dessa resolução `authorize()` roda antes de `rules()`. Isso importa porque, se a autorização só existisse no controller (depois da validação automática do FormRequest), alguém sem permissão que mandasse um corpo inválido receberia **422** primeiro — o que revela que o servidor chegou a examinar e reprovar o conteúdo do corpo, informação que quem não tem acesso nenhum não deveria ganhar. Com a autorização no `authorize()`, a resposta é sempre **403** para quem não pode, corpo válido ou não — o teste `responde 403 antes de validar o corpo` (`InvitationTest.php`) manda `papel: 'dono'` (papel inexistente) de propósito e confirma que o retorno é 403, não 422.

## 5. `scopeBindings()`: o convite tem que pertencer à casa da URL

Primeiro, o que já existe sem isso: **route model binding**. Quando a rota declara `{household}`, o Laravel não entrega o `id` cru ao controller — busca o registro no banco e injeta o objeto `Household` pronto. O mesmo vale para `{invitation}`.

O problema: por padrão, `{invitation}` é buscado **sozinho**, sem saber que está dentro de `households/{household}/...` na URL. Isso abre a porta para **IDOR** (*Insecure Direct Object Reference*) — quando um sistema aceita um ID vindo da URL e busca o registro correspondente sem checar se ele *pertence* a quem está pedindo. Exemplo concreto aqui: um admin da casa X descobre (ou adivinha) que o convite de `id=7` existe, mas pertence à casa Y. Sem proteção, `DELETE /households/X/invitations/7` acharia o convite 7 normalmente — a policy checa "é admin da casa X?" (sim), mas nunca checa se o convite 7 é da casa X.

```php
Route::prefix('households/{household}')
    ->scopeBindings()
    ->group(function () {
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
```

`scopeBindings()` muda a busca do `{invitation}`: passa a exigir que ele seja filho do `{household}` já resolvido (via relação `household()`), ou devolve **404** antes de o controller rodar. O teste `não revoga convite de outra casa pela URL da própria casa` confirma: convite de outra casa, mesmo admin autenticado na sua própria, resultado é 404. A defesa contra IDOR aqui está na **resolução da rota**, não numa checagem manual espalhada pelo controller.

## 6. `InvitationResource`: uma camada entre o model e o JSON

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'papel' => $this->role->value,
        'situacao' => $this->situacao(),
        'expira_em' => $this->expires_at->toIso8601String(),
        // ...
    ];
}
```

Sem essa classe, o caminho mais curto é devolver o model direto (`return $invitation`) e deixar o Eloquent serializar tudo que não está marcado como oculto. É frágil: basta alguém adicionar uma coluna sensível ao model amanhã para ela vazar sozinha na resposta, porque a lista de "o que aparece" é implícita. Com `Resource`, a lista é uma **lista de permissão explícita** — só sai o que está escrito no `toArray()`, e nada mais, mesmo que o model ganhe colunas novas depois. É também onde mora campo derivado que não existe como coluna: `situacao()` olha três timestamps (`accepted_at`, `revoked_at`, `expires_at`) e devolve uma palavra (`pendente`/`aceito`/`revogado`/`expirado`) — o front não precisa reimplementar essa lógica de datas, só ler a string pronta.

## 7. Rate limiting: por conta **e** por IP

**Rate limiting** é simplesmente limitar quantas requisições uma chave (usuário, IP, o que for) pode fazer numa janela de tempo, recusando o excedente. O limitador do aceite de convite:

```php
RateLimiter::for('aceite-convite', fn (Request $request) => [
    Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier()),
    Limit::perMinute(20)->by('ip:'.$request->ip()),
]);
```

A sutileza: limitar só por conta autenticada pareceria suficiente, mas o cadastro do MiCasa é público e gratuito — um atacante tentando adivinhar tokens (ou testar convites revogados/expirados em série) contornaria um limite por conta simplesmente criando conta nova a cada bloqueio. Combinando conta **e** IP, criar contas descartáveis não escapa do segundo limite, porque todas nascem do mesmo IP. Nenhum dos dois é a primeira barreira de verdade — essa é o token de 40 caracteres aleatórios, praticamente impossível de adivinhar; o rate limit é defesa em profundidade, para o caso de o token vazar por outro canal.

## 8. A condição de corrida: o achado central desta entrega

Este é o bug mais sério que a entrega expôs, e vale entender com calma.

O padrão errado, chamado **check-then-act** ("verifica, depois age"), é o que qualquer pessoa escreveria primeiro: ler o convite do banco, checar `isUsable()` (não foi aceito, não foi revogado, não expirou) e, se passou, gravar que foi aceito. Parece correto lendo de cima a baixo — e é, **para uma única requisição por vez**. O problema é que entre o "ler e checar" e o "gravar" existe uma janela de tempo, por menor que seja, e o servidor atende requisições concorrentes.

Cenário concreto: o link do convite foi mandado num grupo de WhatsApp da família, e duas pessoas clicam nele quase ao mesmo tempo. As duas requisições chegam quase juntas, as duas leem o mesmo convite do banco, as duas veem `isUsable() === true` (nenhuma delas gravou nada ainda), e as duas seguem para o `attach()`. Resultado: um convite de uso único vira duas entradas na casa — e se o convite era de papel `admin`, duas pessoas ganham posição de admin com um único link pensado para uma.

A correção não está na leitura — está em transformar a gravação num único comando atômico que só um dos concorrentes pode "ganhar":

```php
$reivindicado = Invitation::query()
    ->whereKey($invitation->id)
    ->whereNull('accepted_at')
    ->whereNull('revoked_at')
    ->where('expires_at', '>', now())
    ->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

if ($reivindicado === 0) {
    throw self::conviteInvalido();
}
```

Esse `UPDATE ... WHERE accepted_at IS NULL ...` é a **reivindicação** do convite. O banco de dados garante, por construção, que um `UPDATE` é uma operação indivisível: das duas requisições concorrentes, uma executa o `UPDATE` e grava a linha primeiro; quando a segunda executa o dela, a condição `accepted_at IS NULL` já não bate mais (a primeira já preencheu), então ela afeta **0 linhas**. `$reivindicado` é justamente a contagem de linhas afetadas — 1 significa "eu venci a corrida", 0 significa "outra requisição chegou primeiro", e só quem venceu segue para o `attach()`.

Por que um `UPDATE` condicional em vez de travar a linha com `lockForUpdate()` (o `SELECT ... FOR UPDATE` do SQL)? Portabilidade: o driver SQLite do Laravel ignora essa cláusula de lock — não dá erro, mas também não trava nada, então em SQLite `lockForUpdate()` não protegeria coisa alguma. Como o MiCasa roda em SQLite, a garantia precisava vir de uma operação cuja atomicidade não depende de suporte a lock explícito — e todo banco relacional garante atomicidade de um `UPDATE` isolado, com ou sem `FOR UPDATE`.

A checagem antiga (`isUsable()`, chamada logo no início de `AcceptInvitation::handle()`) **continua no código** — mas seu papel mudou. Ela não é mais a garantia de segurança; é só quem decide devolver rápido uma mensagem amigável no caso comum (convite claramente expirado, sem precisar tentar o `UPDATE`). Quem garante que o convite não é usado duas vezes, sob concorrência real, é só o `UPDATE` condicional.

## 9. Como o teste prova a correção (red-green)

Simular duas requisições HTTP simultâneas de verdade num teste automatizado é difícil e vira teste instável (às vezes pega a corrida, às vezes não). O teste usa outro caminho, determinístico:

```php
Invitation::retrieved(function (Invitation $invitation) use ($primeiro) {
    if ($invitation->accepted_at === null) {
        DB::table('invitations')->where('id', $invitation->id)
            ->update(['accepted_at' => now(), 'accepted_by' => $primeiro->id]);
    }
});
```

`retrieved` é um **evento do Eloquent**: dispara toda vez que um model é carregado do banco. O teste registra um listener que, no exato instante em que `AcceptInvitation` carrega o convite (`Invitation::firstWhere(...)`), aproveita a brecha e grava por fora que outra pessoa (`$primeiro`) já aceitou — simulando, de forma controlada, a segunda requisição vencendo a corrida bem no meio da primeira. Sem essa simulação artificial, testar a corrida exigiria threads ou processos reais, que existem, mas raramente pegam a janela exata todo dia.

O motivo de este teste importar mais do que parece: ele foi rodado **sem** a correção (só com o `isUsable()` seguido de `attach()`) e falhou — a segunda pessoa conseguia entrar na casa. Depois, com o `UPDATE` condicional em vigor, o mesmo teste passou. Essa sequência (vermelho, depois verde) é o que dá confiança de que o teste realmente testa o bug: um teste que nunca foi visto falhando contra o código errado não prova que ele detectaria o problema — pode estar testando outra coisa, ou nada.

## 10. O papel do Adversário de Segurança

O fluxo de trabalho do projeto reserva, ao fim de cada fatia com dado sensível, uma revisão feita por um agente com um único objetivo: tentar quebrar o que foi construído (autorização horizontal, IDOR, atribuição em massa — ver `prompt-casa-os.md`). Para esta fatia (casas, membros, convites), essa revisão era obrigatória, e ela **reprovou a primeira versão** — foi assim que a condição de corrida da seção 8 apareceu, antes de chegar a produção. Reprovar não foi um imprevisto do processo: é exatamente para isso que essa etapa existe.

Vale registrar também o que **não** foi corrigido, por decisão consciente: a distinção entre 404 e 403 ao longo da API entrega, para quem tenta IDs por tentativa e erro, se um determinado ID *existe* (403: existe, mas não é seu) ou não (404). Isso não é peculiaridade dos convites — é como o binding de rota do Laravel se comporta em **toda** a API deste projeto. Corrigir só aqui deixaria o comportamento inconsistente entre endpoints; se um dia isso for tratado, precisa ser uma decisão de arquitetura aplicada globalmente (por exemplo, um tratamento central de exceção), não um remendo pontual num único controller.

## 11. Como testar manualmente

| Método | Rota | Quem chama | O que faz |
|---|---|---|---|
| `POST` | `/api/households/{id}/invitations` | admin da casa | Cria convite, devolve `token` em claro (só agora) |
| `GET` | `/api/households/{id}/invitations` | admin da casa | Lista convites da casa (sem token/hash) |
| `DELETE` | `/api/households/{id}/invitations/{invitationId}` | admin da casa | Revoga convite ainda não aceito |
| `POST` | `/api/invitations/{token}/accept` | qualquer usuário logado | Aceita o convite, vira membro |

Via curl, a sessão exige o par cookie+CSRF do Sanctum (fluxo completo no doc 02). Resumo prático, com um admin já logado num `cookies.txt`:

```bash
# criar convite (papel opcional: "admin" ou "member", default member)
curl -b cookies.txt -c cookies.txt -X POST \
  http://localhost:8000/api/households/1/invitations \
  -H "X-XSRF-TOKEN: $CSRF" -H "Accept: application/json" \
  -d '{"papel":"member"}'
# → {"data": {...}, "token": "aBc123..."}

# outro usuário (cookies2.txt) aceita o token recebido
curl -b cookies2.txt -c cookies2.txt -X POST \
  http://localhost:8000/api/invitations/aBc123.../accept \
  -H "X-XSRF-TOKEN: $CSRF2" -H "Accept: application/json"
```

Mais simples na prática: um cliente HTTP com cookie jar automático (Postman, Insomnia) — faz o handshake de CSRF sozinho e evita reescrever o `-b`/`-c` a cada chamada. `php artisan test --filter=InvitationTest` roda todo o cenário sem precisar de nada disso.

## 12. No mercado

| Prática | Quão comum |
|---|---|
| Convite por link com token de uso único, em vez de e-mail | Comum em produtos B2C/família (Notion, grupos de WhatsApp Business); trade-off de infraestrutura, não regra fixa |
| Hash de token de convite (nunca guardar em claro) | Prática básica esperada; token em claro no banco é falha clássica de auditoria de segurança |
| Policy do Laravel para autorização por recurso | Padrão do framework; presente em praticamente todo projeto Laravel com mais de um papel de usuário |
| Autorização no FormRequest antes da validação | Detalhe fino, mas cobrado em revisão de segurança séria — ordem de checagem que evita vazar informação por timing/status code |
| `scopeBindings()` / defesa contra IDOR em rota aninhada | Divisor júnior/pleno em back-end; IDOR está no OWASP Top 10 (categoria de controle de acesso quebrado) há anos |
| API Resource como camada de serialização | Onipresente em API Laravel madura; alternativa (`toArray()` do model ou model cru) é sinal de projeto iniciante |
| Rate limit combinando múltiplas chaves (conta + IP) | Comum em endpoint sensível (login, aceite de convite, reset de senha); rate limit de uma chave só é erro comum |
| Condição de corrida em fluxo "verificar depois gravar" | **Pergunta clássica de entrevista sênior** ("o que acontece se dois cliques chegarem juntos?"); a maioria do código em produção por aí ainda tem esse bug sem saber |
| `UPDATE` condicional como técnica de concorrência | Alternativa real a lock explícito, mais portável entre bancos; usada em sistemas de fila, reserva de estoque, qualquer "primeiro a chegar, leva" |
| Teste que reproduz corrida via evento do ORM | Técnica pouco ensinada, mas é assim que times sérios testam concorrência sem depender de sorte de timing |
| Revisão de segurança dedicada, com poder de reprovar | Prática de time maduro (security review / red team interno); rara em projeto solo, aqui simulada de propósito no processo |
