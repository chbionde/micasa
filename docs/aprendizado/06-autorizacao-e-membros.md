# Aprendizado 06 — Autorização em camadas, IDOR e o último administrador

> O que foi construído na entrega de policies e gestão de membros: listar as próprias casas, ver quem mora nelas, promover/rebaixar papel, remover pessoa, sair da casa e trocar a casa ativa. O fio condutor é autorização — não uma checagem, mas três defesas independentes — e dois achados de teste que revelaram decisões corretas do código.

---

## 1. O que esta entrega faz

Seis operações novas, todas em cima do que a Fatia de convites (doc 05) já tinha montado:

- `GET /households` — listar as casas de que eu participo.
- `GET /households/{id}/members` — ver quem mora numa casa.
- `PATCH /households/{id}/members/{id}` — promover ou rebaixar o papel de alguém.
- `DELETE /households/{id}/members/{id}` — remover alguém (ou sair, quando o alvo sou eu mesmo).
- `PUT /user/active-household` — trocar qual casa está "em foco" no momento.
- `GET /user` ganha o campo `casa_ativa`, já com o papel de quem pediu.

Nada aqui cria dado novo — é tudo sobre **quem pode ver e mexer no que já existe**. Por isso a entrega inteira gira em torno de autorização.

## 2. Autorização em três camadas independentes

O código deste projeto não confia numa checagem só. Para qualquer rota de membro, três mecanismos diferentes precisam concordar antes de um dado sensível chegar à resposta:

| Camada | Pergunta que responde | Onde mora |
|---|---|---|
| Escopo de query | "Que linhas eu nem deveria ver existirem?" | Controller (`$request->user()->households()`) |
| `scopeBindings()` | "O recurso da URL pertence à casa da URL?" | Rota (`routes/api.php`) |
| Policy | "Esta pessoa tem o papel certo para esta ação?" | `HouseholdPolicy` |

São defesas em **profundidade**: cada uma cobre um jeito diferente de a mesma falha acontecer. Uma Policy correta não ajuda se a query já trouxe o registro errado para dentro do processo. Um `scopeBindings()` correto não ajuda se a Policy deixar qualquer autenticado mexer em qualquer casa. E nenhuma das duas impede um `Household::all()` solto em algum canto de vazar tudo antes de a Policy ser chamada. Cada camada assume que a anterior pode falhar, e protege mesmo assim — por isso não basta uma só.

## 3. A defesa mais importante: a query nasce restrita

```php
// HouseholdController::index()
public function index(Request $request): AnonymousResourceCollection
{
    return HouseholdResource::collection(
        $request->user()->households()->orderBy('name')->get()
    );
}
```

`$request->user()->households()` é uma relação `belongsToMany` — o SQL gerado já tem, embutido, um `WHERE` que só busca casas ligadas a este usuário pela tabela pivô. Compare com a alternativa perigosa, `Household::all()`, que traria a tabela inteira e dependeria de alguém lembrar de filtrar depois.

A diferença não é estilo, é categoria de bug evitado. Se a consulta já nasce restrita, não existe "esqueci de checar permissão" — o dado de outra casa **nunca entra na memória do processo**, então não há caminho de código, hoje ou daqui a seis meses, que consiga expor esse dado por acidente. É a invariante 4 do modelo de domínio do projeto: *"Toda query é escopada pela casa (...). Membro de A jamais lê recurso de B — testado explicitamente, por fatia."* Policy bem escrita é rede de segurança; query bem escrita é a ausência do buraco por onde a rede precisaria pegar algo.

## 4. IDOR: quando o ID da URL não é seu, mas o servidor busca assim mesmo

**IDOR** (*Insecure Direct Object Reference*) é a falha de aceitar um ID vindo da URL e buscar o registro correspondente sem checar se ele *pertence* a quem está pedindo. Exemplo concreto: eu sou membro só da casa 1; um "vizinho" mora na casa 2. Nada me impede de tentar `PATCH /api/households/1/members/5` com `{"papel": "admin"}`, onde `5` é o ID do vizinho — um número que não preciso adivinhar, só testar em sequência. Sem proteção, a Policy checaria "sou admin da casa 1?" (sim) e pararia aí — ela nunca pergunta se o membro `5` é *da casa 1*. É exatamente o papel do `scopeBindings()` na rota:

```php
Route::prefix('households/{household}')
    ->scopeBindings()
    ->group(function () {
        Route::patch('/members/{member}', [MemberController::class, 'update']);
        Route::delete('/members/{member}', [MemberController::class, 'destroy']);
    });
```

Isso muda como o Laravel resolve `{member}`: ele deixa de buscar o usuário `5` sozinho e passa a exigir que ele apareça na relação `members()` da casa `1` já resolvida. Se não aparecer, a resposta é **404** antes de controller ou Policy rodarem. O teste prova os dois verbos:

```php
it('não alcança membro de outra casa pela URL da própria casa', function () {
    // $vizinho mora só na casa de outra pessoa
    $this->actingAs($eu)
        ->patchJson("/api/households/{$minhaCasa->id}/members/{$vizinho->id}", ['papel' => 'admin'])
        ->assertNotFound();

    $this->actingAs($eu)
        ->deleteJson("/api/households/{$minhaCasa->id}/members/{$vizinho->id}")
        ->assertNotFound();
});
```

O ponto que vale reter: essa defesa está na **resolução da rota**, não numa checagem manual espalhada pelo controller — não tem como um desenvolvedor esquecer de escrevê-la de novo em cada `action` nova sobre `{member}`, porque ela não é por-action, é da rota inteira.

## 5. A invariante do último administrador

Uma casa sem nenhum admin fica órfã: ninguém convida gente nova, ninguém revoga convite, ninguém promove ou remove membro — a casa trava em estado que só um `UPDATE` manual no banco resolveria. Por isso a regra: **não dá para rebaixar nem remover o único administrador restante**.

A regra mora nas **Actions**, não no controller:

```php
// UpdateMemberRole::handle() e RemoveMember::handle() fazem a mesma pergunta
if ($papelAtual->isAdmin() && self::ehUltimoAdmin($household, $member)) {
    throw ValidationException::withMessages([
        'papel' => 'A casa precisa de pelo menos um administrador. Promova outra pessoa antes.',
    ]);
}

public static function ehUltimoAdmin(Household $household, User $member): bool
{
    return $household->admins()->where('users.id', '!=', $member->id)->doesntExist();
}
```

Duas operações diferentes — rebaixar e remover — mas a mesma pergunta de fundo: "se eu tirar o papel de admin desta pessoa, sobra algum outro admin?" Em vez de escrever a mesma consulta duas vezes (e arriscar as duas versões divergirem com o tempo), `RemoveMember` chama o método estático `ehUltimoAdmin` que já existe em `UpdateMemberRole`. É por isso que a regra vive nas Actions e não no `MemberController`: o controller é onde a requisição HTTP chega e sai; a regra de negócio — "casa sem admin é estado inválido" — não depende de HTTP nenhum, e colocá-la na Action deixa isso reaproveitável e testável sem precisar simular uma requisição inteira.

## 6. Sair da própria casa vs. remover outra pessoa

`MemberController::destroy` atende os dois casos com a mesma rota, mas com exigências de autorização diferentes:

```php
if ($request->user()->id !== $member->id) {
    $this->authorize('manageMembers', $household); // remover terceiro: exige admin
} else {
    $this->authorize('view', $household); // sair: qualquer membro pode
}
```

A diferença de rigor reflete a diferença de risco. Remover outra pessoa é uma ação sobre o direito de terceiro — errar aqui expulsa alguém da casa sem consentimento, por isso exige `manageMembers` (papel de admin). Sair da própria casa só afeta quem está pedindo — não existe cenário em que isso prejudique outra pessoa além de quem decidiu sair, então basta `view` (ser membro), a mesma exigência mínima de "eu tenho alguma relação com esta casa". Autorizar as duas coisas com a mesma régua seria ou permissivo demais (qualquer membro expulsando qualquer um) ou restritivo demais (precisar pedir para um admin só para sair de uma casa).

## 7. Limpar o estado ao sair: `active_household_id`

Cada usuário tem uma "casa ativa" (`active_household_id` em `users`) — a casa que a interface mostra por padrão. Sair de uma casa que era a ativa deixaria esse campo apontando para um vínculo que não existe mais, se nada tratasse isso:

```php
// RemoveMember::handle(), dentro da transação
if ($member->active_household_id === $household->id) {
    $member->active_household_id = $member->households()->value('households.id');
    $member->save();
}
```

A pessoa cai em outra casa de que ainda participa (a primeira que a consulta encontrar), ou em `null` se não sobrar nenhuma. A razão de tratar isso explicitamente: estado inconsistente é a fonte de bug mais difícil de rastrear que existe, porque o erro não aparece na hora da operação que o causou — aparece depois, em qualquer tela que confiar em `active_household_id` sem saber que ele pode estar apontando para o vazio. É mais barato garantir a consistência no exato momento em que ela quebraria (dentro da mesma transação do `detach()`) do que se defender dela em todo lugar que lê o campo depois.

## 8. O envelope `data` do API Resource

Um `JsonResource` do Laravel não devolve o JSON pronto direto — ele embrulha o resultado num objeto com uma chave `data`, algo como `{ "data": { "id": 1, "nome": "Casa da Família", ... } }`. O motivo do envelope é deixar espaço para metadados que não fazem parte do conteúdo em si — o exemplo clássico é paginação (`meta`, `links`), que uma coleção paginada de Resources ganha automaticamente ao lado de `data`, sem precisar mudar o formato de cada item dentro dela. Isso significa que **toda** resposta de Resource neste projeto — `UserResource`, `HouseholdResource`, `MemberResource`, coleção ou item único — carrega essa camada extra, sempre.

A consequência do lado do front: quem consome a API precisa desembrulhar. `web/src/lib/api.ts` deixa esse contrato explícito em vez de implícito, e `AuthContext.tsx` o usa:

```typescript
export type Envelope<T> = { data: T }               // lib/api.ts
const { data } = await api.get<Envelope<User>>('/api/user')
setUser(data.data) // data é o Envelope; data.data é o User de dentro
```

`data.data` parece redundante lendo rápido, mas cada `data` significa uma coisa diferente: o primeiro é o corpo da resposta HTTP (o Axios já desembrulha isso); o segundo é o campo `data` que o Laravel colocou dentro desse corpo. Nomear o tipo (`Envelope<T>`) em vez de deixar `any` ou um objeto solto é o que torna esse desembrulho um contrato visível no TypeScript, e não um detalhe que cada chamada à API precisa redescobrir sozinha.

## 9. A armadilha dos mocks desatualizados

Este é o ponto que mais vale destacar desta entrega, porque é um erro fácil de cometer e fácil de não perceber.

Os testes do front (`LoginPage.test.tsx`, `RegisterPage.test.tsx`, `RequireAuth.test.tsx`) não fazem requisição HTTP de verdade — eles simulam o Axios com **mocks**: funções falsas que devolvem um valor combinado de antemão, no lugar de ir à rede. Antes desta entrega, `/api/user` devolvia o usuário direto, sem envelope, e os mocks refletiam isso:

```typescript
// ANTES: const usuario = { id: 1, name: 'Carlos', email: '...' }
// DEPOIS, igual à API real:
const usuario = { data: { id: 1, name: 'Carlos', email: '...', casa_ativa: null } }
```

Quando `UserResource` entrou em cena e a API passou a devolver `{ data: { ... } }`, o código de produção (`AuthContext.tsx`) foi atualizado para ler `res.data.data`. Só que, se os mocks tivessem ficado no formato antigo, cada teste continuaria passando — porque o mock, sendo uma resposta fabricada à mão, não sabe que a API mudou. `data.data` num mock desatualizado vira `undefined`, o teste não trava nisso, e o suite fica **verde mentindo**: passando não porque o código está certo, mas porque nunca chegou a exercitar o formato real. A correção foi atualizar os três mocks para o formato novo.

A lição geral, não específica deste projeto: um mock é uma promessa escrita à mão sobre como uma dependência externa se comporta. Nada o obriga a acompanhar a dependência real quando ela muda — é responsabilidade de quem programa lembrar de atualizar os dois lados. Esse descompasso é o principal ponto fraco de testar com dublês (mocks, stubs, fakes): o teste continua verde, mas para de significar "o código funciona" e passa a significar só "o código bate com uma suposição antiga".

## 10. Mass assignment protegido, descoberto por um teste falhando

`User` declara explicitamente quais campos aceitam atribuição em massa:

```php
#[Fillable(['name', 'email', 'password'])]
class User extends Authenticatable
```

**Mass assignment** é a prática de preencher vários atributos de um model de uma vez a partir de um array (`$model->update($array)` ou `$model->fill($array)`), em vez de atribuir campo por campo. É conveniente, mas perigoso se o array vier direto de input do usuário sem filtro: um formulário que manda `{"name": "...", "email": "...", "is_admin": true}` conseguiria virar admin sozinho, se `is_admin` estivesse liberado para preenchimento em massa. `#[Fillable]` é a lista de permissão — só os campos nela aceitam esse caminho; qualquer outro é ignorado silenciosamente.

`active_household_id` fica **de fora** de propósito: não é algo que deveria vir de um `update()` genérico alimentado por request, só da Action dedicada (`SwitchActiveHousehold`, que valida antes). Isso apareceu na prática como teste falhando: os testes desta entrega inicialmente tentavam preparar o cenário de "usuário com casa ativa X" usando `$user->update(['active_household_id' => $id])`, e o campo simplesmente não mudava — porque não está no `#[Fillable]`, `update()` o ignora. O código de produção estava certo; os testes é que usavam um caminho que o próprio model existe para bloquear. A correção não foi no model, foi no teste, e ficou documentada no próprio helper:

```php
/**
 * active_household_id fica fora do #[Fillable] de propósito — não pode vir
 * de input do usuário —, então aqui atribuímos direto.
 */
function definirCasaAtiva(User $user, Household $household): void
{
    $user->active_household_id = $household->id;
    $user->save();
}
```

Atribuição direta de propriedade (`$user->active_household_id = ...`) contorna o filtro do `Fillable` porque esse filtro só existe para o caminho de array em massa — é o jeito certo de um teste (ou de código interno confiável) preparar esse campo, sem abrir a mesma porta para uma requisição HTTP.

## 11. Endpoints novos

| Método | Rota | Quem pode | Ação |
|---|---|---|---|
| `GET` | `/api/households` | qualquer autenticado | Lista só as próprias casas |
| `GET` | `/api/user` | qualquer autenticado | Usuário logado + `casa_ativa` |
| `PUT` | `/api/user/active-household` | membro da casa alvo | Troca a casa ativa |
| `GET` | `/api/households/{id}/members` | membro da casa | Lista quem mora na casa |
| `PATCH` | `/api/households/{id}/members/{id}` | admin da casa | Promove/rebaixa papel |
| `DELETE` | `/api/households/{id}/members/{id}` | o próprio (`view`) ou admin (`manageMembers`) | Sai da casa / remove membro |

Vale notar que `PUT /user/active-household` não passa por uma `HouseholdPolicy` — a checagem de pertencimento acontece dentro da própria Action (`SwitchActiveHousehold::handle`), e uma falha vira `422` (erro de validação), não `403`. É uma escolha consistente com o que a rota representa: trocar de contexto não é uma ação *sobre* a casa (não muda nada nela), é uma preferência do próprio usuário — por isso o erro fica mais perto de "valor inválido" do que de "acesso negado".

## 12. Como testar manualmente

Com sessão de admin já aberta (cookie + CSRF, fluxo completo no doc 02):

```bash
# listar minhas casas / ver membros da casa 1
curl -b cookies.txt http://localhost:8000/api/households -H "Accept: application/json"
curl -b cookies.txt http://localhost:8000/api/households/1/members -H "Accept: application/json"

# promover membro 5 a admin; trocar casa ativa
curl -b cookies.txt -X PATCH http://localhost:8000/api/households/1/members/5 \
  -H "X-XSRF-TOKEN: $CSRF" -H "Accept: application/json" -d '{"papel":"admin"}'
curl -b cookies.txt -X PUT http://localhost:8000/api/user/active-household \
  -H "X-XSRF-TOKEN: $CSRF" -H "Accept: application/json" -d '{"household_id":2}'
```

Suíte automatizada (cobre IDOR, último admin, limpeza de estado e as 403/404/422 de cada cenário):

```bash
php artisan test --filter=MemberManagementTest
```

## 13. No mercado

| Prática | Quão comum |
|---|---|
| Autorização em três camadas (query + binding + Policy) | Marca de time maduro; código júnior costuma confiar numa camada só |
| Escopo de query como defesa primária, não a Policy | Pouco ensinado, mas é o que elimina a classe de bug — Policy sozinha é rede de segurança, não ausência do risco |
| `scopeBindings()` / defesa de IDOR pela resolução de rota | Divisor júnior/pleno; IDOR está no OWASP Top 10 (quebra de controle de acesso) há anos |
| Regra de negócio numa Action, reaproveitada por método estático | Comum em Laravel além do CRUD básico; controller com `if` de regra de negócio é sinal de responsabilidades não separadas |
| Invariante "não pode ficar sem admin" | Padrão em qualquer sistema com papéis (Slack, GitHub, Notion); toda plataforma com "workspace" tem essa regra |
| Autorização diferenciada por risco (self-service vs. ação sobre terceiro) | Comum em API madura; tratar as duas com a mesma regra é simplificação de MVP que cobra preço depois |
| Limpar estado derivado dentro da mesma transação da causa | Prática de time disciplinado; "esqueci de limpar o ponteiro" é bug recorrente em campo tipo "ativo/atual" |
| Envelope `data` do API Resource + tipo explícito no front | Padrão de quem usa Laravel API Resources a sério; ignorar o envelope é erro comum de quem começou com API mais simples |
| Mock de teste desatualizado em relação ao contrato real | Risco conhecido de testar com dublês; poucos times têm processo além de disciplina manual ao mudar a API |
| Mass assignment protegido por whitelist explícita (`Fillable`) | Prática básica esperada; deixar tudo "fillable" por preguiça é falha clássica de auditoria |
