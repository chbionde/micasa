# Aprendizado 04 — Modelagem multi-casa: tabela pivô, enum, integridade referencial e convite por token

> O que foi construído para a issue de multi-casa (ADR-007), explicado do zero: como o banco sabe que uma pessoa pode morar em duas casas com papéis diferentes, e como um convite entra sem nunca guardar segredo em claro.

---

## 1. Por que multi-casa desde o começo

O MiCasa poderia ter sido modelado assim: "existe uma casa, existem usuários, ponto". Seria mais simples de escrever hoje. O problema é o dia em que aparece o segundo cenário real — alguém que administra a própria casa e também participa da casa dos pais, ou uma diarista que atende duas casas.

Se o usuário fica amarrado direto a uma casa (uma coluna `household_id` em `users`), suportar várias casas depois exige criar tabela nova, migrar dado de produção sem perder nada, reescrever toda consulta que assumia "uma casa só" e revisar cada regra de permissão — retrabalho caro e arriscado num sistema já em uso. Por isso o ADR-007 decidiu **multi-casa desde a primeira migration**, mesmo o uso do dia 1 sendo simples: é o raciocínio de qualquer sistema **multi-tenant** (múltiplos "inquilinos" independentes na mesma aplicação, como um SaaS B2B), onde modelar para o caso geral é mais barato agora do que migrar depois.

## 2. A tabela pivô `household_user`

Uma casa tem várias pessoas; uma pessoa pode estar em várias casas — relação **muitos para muitos**, que não cabe numa chave estrangeira simples (essa só aponta para um registro do outro lado). A solução é uma **tabela pivô**: existe só para ligar duas tabelas, uma linha por par.

```php
Schema::create('household_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('household_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role');
    $table->timestamps();

    $table->unique(['household_id', 'user_id']);
});
```

O nome `household_user` segue a convenção do Eloquent (as duas tabelas, singular, ordem alfabética) — assim `belongsToMany()` acha a tabela sozinho. O ponto central: **o papel mora na linha do pivô, não em `users`**. Se `role` estivesse no usuário, a pessoa só poderia ter um papel em todo lugar. No pivô, cada combinação casa+pessoa tem seu próprio papel: admin na própria casa, member na casa dos pais.

```php
public function members(): BelongsToMany
{
    return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
}
```

`withPivot('role')` diz ao Eloquent para trazer essa coluna extra junto (por padrão só viriam as chaves). O teste comprova o cenário completo:

```php
$minhaCasa->members()->attach($pessoa->id, ['role' => HouseholdRole::Admin->value]);
$casaDosPais->members()->attach($pessoa->id, ['role' => HouseholdRole::Member->value]);

expect($pessoa->roleIn($minhaCasa))->toBe(HouseholdRole::Admin)
    ->and($pessoa->roleIn($casaDosPais))->toBe(HouseholdRole::Member);
```

## 3. Chave estrangeira e integridade referencial

Uma **chave estrangeira** é uma coluna que só aceita valores que existem como chave primária em outra tabela — o banco garante isso sozinho, sem depender do PHP nunca errar. `constrained()` cria essa restrição inferindo a tabela pelo nome da coluna (`household_id` → `households`). A pergunta que sobra: o que fazer quando o registro apontado é apagado?

| Opção | Comportamento | Onde é usada |
|---|---|---|
| `cascadeOnDelete()` | Apaga em cascata: apagou a casa, as linhas dependentes somem junto | `household_user.*`, `invitations.household_id`, `invitations.created_by` |
| `nullOnDelete()` | Zera a referência: a coluna vira `NULL` em vez de apontar para nada | `users.active_household_id`, `invitations.accepted_by` |

```php
// casa apagada devolve o usuário a "sem casa ativa", não a um ponteiro quebrado
$table->foreignId('active_household_id')->nullable()->constrained('households')->nullOnDelete();
```

Sem nenhuma das duas opções, o banco recusaria apagar a casa enquanto houvesse dependentes (padrão `restrict`) — ou, sem chave estrangeira nenhuma, o `DELETE` passaria e deixaria um `id` apontando para nada (registro **órfão**), bug que só aparece longe de onde foi causado. O teste cobre a cascata escolhida: `$casa->delete()` e depois `expect($pessoa->fresh()->active_household_id)->toBeNull()`.

## 4. Índice único composto: o banco como última linha de defesa

```php
$table->unique(['household_id', 'user_id']);
```

Isso cria um **índice único composto**: o **par** `(household_id, user_id)` não pode se repetir — a mesma pessoa não pode ter duas linhas de vínculo com a mesma casa (a mesma casa aparece várias vezes, uma por membro; a mesma pessoa aparece várias vezes, uma por casa).

Dá para escrever a mesma regra só em PHP ("antes de vincular, verifica se já existe"). O problema é a **condição de corrida**: duas requisições simultâneas fazem a verificação ao mesmo tempo, nenhuma viu o vínculo da outra ainda, e as duas passam. Validar na aplicação é sobre experiência (erro cedo, mensagem amigável); garantir no banco é sobre impossibilidade, mesmo sob concorrência. O teste comprova que o banco barra mesmo sem checagem prévia — vincular a mesma pessoa duas vezes na mesma casa lança `QueryException`, não silêncio.

## 5. Enum do PHP e cast do Eloquent

Antes do PHP 8.1, "o papel é admin ou member" existia só como comentário — nada impedia `role: 'admni'` (erro de digitação) de ser salvo e virar bug silencioso, porque a comparação `=== 'admin'` nunca bate e ninguém percebe na hora.

```php
enum HouseholdRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function isAdmin(): bool { return $this === self::Admin; }
}
```

É um **enum apoiado em string** (*backed*): cada caso tem um valor associado, que é o que fica salvo na coluna. Na aplicação, porém, o valor nunca circula solto — circula como `HouseholdRole::Admin`, um objeto real. Passar algo fora da lista (`HouseholdRole::from('admni')`) lança exceção na hora, em vez de silenciosamente nunca bater.

No model `Invitation`, o Eloquent converte sozinho, coluna ↔ enum, via `casts()`:

```php
protected function casts(): array
{
    return ['role' => HouseholdRole::class, 'expires_at' => 'datetime' /* ... */];
}
```

Diferença sutil: essa conversão automática é do **model** — vale para `$invitation->role`. A coluna `role` de `household_user` é atributo de **pivô**, não passa por `casts()` de ninguém. Por isso `Household::roleOf()` converte à mão: `HouseholdRole::from($membro->pivot->role)`.

## 6. Token hasheado no convite: o mesmo raciocínio da senha

```php
// vazar o banco não permite entrar em casa alguma
$table->string('token_hash')->unique();
```

Mesmo raciocínio de nunca guardar senha em claro (doc 02): se a coluna guardasse o token pronto para uso, um vazamento do banco entregaria acesso a qualquer casa com convite pendente. Guardando o **hash** (SHA-256 — transforma o token num texto fixo, de mão única, impossível de reverter), o vazamento não entrega nada utilizável. O token em claro só existe em um momento: a resposta HTTP de quando o convite é criado. Depois disso, nem o próprio sistema consegue recuperá-lo — perdeu o link, a solução é revogar e criar outro.

## 7. Factories: dados de teste sem repetição

Uma **factory** monta um registro válido do model com dados plausíveis, para o teste não escrever `Household::create([...])` completo toda vez.

```php
public function withAdmin(?User $user = null): static
{
    return $this->afterCreating(function (Household $household) use ($user) {
        $household->members()->attach(
            ($user ?? User::factory()->create())->id,
            ['role' => HouseholdRole::Admin->value],
        );
    });
}
```

`withAdmin()` é um **estado**: uma variação nomeada e reaproveitável ("essa casa, mas já com um admin"). `InvitationFactory` tem o mesmo padrão para os cenários que a regra de negócio precisa distinguir:

| Estado | O que muda |
|---|---|
| `expired()` | `expires_at` no passado |
| `revoked()` | `revoked_at` preenchido |
| `accepted()` | `accepted_at` e `accepted_by` preenchidos |

Sem factory, testar "convite expirado não é utilizável" exigiria montar um convite inteiro só para ajustar uma data. Com estado nomeado, o teste declara a intenção direto: `Invitation::factory()->expired()->create()`.

## 8. Migration reversível: todo `up()` tem seu `down()`

```php
public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropConstrainedForeignId('active_household_id');
    });
}
```

Migration é código que roda contra banco com dados reais, e às vezes uma mudança precisa ser desfeita (deploy errado, migration mal pensada em homologação). Sem `down()` funcional, desfazer vira cirurgia manual. A prova de que o `down()` realmente desfaz — não só existe — é rodar o ciclo completo: aplicar, reverter, aplicar de novo, sem erro em nenhum passo.

## 9. `@property`: ajudando o Larastan a "ver" o cast

O Larastan (análise estática — lê o código sem executar) não sabe sozinho que `$invitation->expires_at` é um objeto `Carbon` em vez do texto da coluna; essa transformação só acontece em tempo de execução, dentro do `casts()`, e análise estática não executa nada.

```php
/**
 * @property HouseholdRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invitation extends Model
```

Foi um erro real nesta entrega: sem `@property Carbon $expires_at`, o Larastan via `$this->expires_at->isFuture()` (dentro de `isUsable()`) e reclamava — para ele, `expires_at` era tipo desconhecido, e chamar `->isFuture()` num tipo desconhecido é exatamente o que a ferramenta existe para pegar. A correção não mudou comportamento: documentou o tipo que o cast já produzia, e o Larastan passou a confiar nele.

## 10. O desenho das tabelas

```
┌───────────┐   1    ┌────────────────────┐    *   ┌───────────┐
│ households│◀──────▶│   household_user    │◀──────▶│   users   │
│ id, name, │        │ household_id (FK)   │        │ id, name, │
│ timezone  │        │ user_id (FK)        │        │ active_   │
└─────┬─────┘        │ role                │        │ household │
      │              │ UNIQUE(hh_id,usr_id)│        │ _id (FK,  │
      │              └──────────────────────┘        │ nullOnDel)│
      │ cascadeOnDelete                              └───────────┘
      ▼
┌─────────────────────────────┐
│ invitations                  │
│ household_id (FK, cascade)   │
│ token_hash (UNIQUE)          │
│ role, created_by (FK, cascade)│
│ accepted_by (FK, nullOnDelete)│
│ expires_at / accepted_at / revoked_at
└─────────────────────────────┘
```

## 11. Como replicar

```bash
# criar uma migration nova (gera o arquivo timestampado)
php artisan make:migration create_households_table

# aplicar todas as migrations pendentes
php artisan migrate

# desfazer as últimas 4 (as desta entrega) — prova que o down() funciona
php artisan migrate:rollback --step=4
php artisan migrate

# suíte de testes (Pest), inclui HouseholdModelTest
php artisan test

# análise estática (Larastan); memória maior porque o projeto já cresceu
vendor/bin/phpstan analyse --memory-limit=1G
```

## 12. No mercado

| Prática | Quão comum |
|---|---|
| Multi-tenant desde o design inicial | Padrão em SaaS B2B; decisão de arquitetura discutida em entrevista de back-end pleno/sênior |
| Tabela pivô com coluna extra (`withPivot`) | Uso corriqueiro do Eloquent em qualquer N:N com metadado (papel, quantidade, data) |
| `cascadeOnDelete` / `nullOnDelete` | Decisão obrigatória em schema relacional; escolher a errada é causa comum de bug de produção |
| Índice único composto como garantia, não só validação de app | Divisor júnior/pleno: "e se duas requisições chegarem ao mesmo tempo?" é pergunta clássica |
| Enum nativo (8.1+) em vez de string solta | Ainda minoria em código legado; recomendação padrão em projeto Laravel novo |
| Hash de token de convite/reset de senha | Prática básica esperada em qualquer sistema com convite; token em claro é falha clássica de auditoria |
| Factories com estados nomeados | Onipresente em projeto Laravel com teste automatizado |
| Migration reversível testada de verdade | Comum em times disciplinados; muitos escrevem `down()` e nunca testam — aqui foi verificado |
| `@property` para ajudar análise estática a enxergar casts | Nicho, mas necessário com Larastan/PHPStan em nível alto e casts customizados — ajuste que só aparece na prática |
