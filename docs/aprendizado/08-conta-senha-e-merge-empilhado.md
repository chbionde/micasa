# Aprendizado 08 — Gestão de conta, recuperação de senha e a armadilha do merge empilhado

> O que foi construído: renomear a casa, sair de uma casa com um membro só, apagar a própria conta, editar perfil e trocar senha, e o fluxo completo de "esqueci minha senha". Cinco lacunas que não vieram de um plano — vieram de testar o sistema como usuário de verdade e esbarrar em parede. O fio condutor aqui não é um conceito só: é uma coleção de decisões pequenas, mais dois bugs pegos por teste e um acidente de processo no merge.

---

## 1. De onde vieram as cinco lacunas

Depois da Fatia 1 (casas, membros, convites — docs 06 e 07), dava para criar uma casa, mas não para renomeá-la. A regra de "último admin" impedia sair dela de qualquer jeito, mesmo morando sozinho. Não havia como apagar a conta, nem tela de "esqueci minha senha", nem tela de conta (perfil, senha) alguma.

Nenhum desses furos veio de um documento de planejamento — apareceram usando o produto: criar uma casa, tentar sair, ver que travava; procurar "editar meu nome" e não achar o lugar. É normal e saudável: planejamento antecipa o previsível, mas "o que a pessoa tenta fazer no primeiro minuto" só aparece testando.

## 2. Casa nunca fica vazia: a regra que faltava

A regra do último administrador (doc 06, seção 5) dizia "não dá para remover o único admin restante". Ao pé da letra, ela também bloqueava quem morava **sozinho** — sozinho, a pessoa é o único admin. Quem criava uma casa (todo cadastro cria uma) ficava presa nela para sempre.

A correção, em `RemoveMember::handle()`:

```php
$ehUltimaPessoa = $household->members()->count() === 1;

if (! $ehUltimaPessoa && $papel->isAdmin() && UpdateMemberRole::ehUltimoAdmin($household, $member)) {
    throw ValidationException::withMessages([...]);
}

if ($ehUltimaPessoa) {
    // Cascata leva vínculo e convites; quem tinha a casa como ativa
    // fica sem casa ativa (nullOnDelete).
    $household->delete();
} else {
    $household->members()->detach($member->id);
}
```

`$ehUltimaPessoa` é o ajuste fino: a regra do último admin só entra em jogo quando **sobra gente na casa**. Sendo a última pessoa, não existe "deixar sem admin" — a casa some com ela, e a cascata do schema leva junto o pivô `household_user` e os convites pendentes (provado no teste `quem sai sozinho apaga a casa e fica sem casa`). A regra do último admin **continua** valendo com outras pessoas: sair sozinho não é o mesmo problema que sair deixando alguém sem quem administra — as duas regras cobrem casos diferentes, não competem.

## 3. Pessoa sem casa: um estado que não existia até ontem

O ADR-007 original dizia: *"Não existe conta órfã"* — todo cadastro cria casa, então toda pessoa sempre tem uma. A regra da seção 2 quebra isso de propósito: quem sai da própria casa (morando sozinho) fica **sem nenhuma casa**. O ADR original e o comportamento novo se contradiziam.

Um **ADR** (*Architecture Decision Record*) é um registro datado de decisão de arquitetura — contexto, opções, escolha, consequências. Não documenta o código, documenta o **porquê**, para quem ler depois entender se o raciocínio ainda vale. Quando a premissa muda, há duas saídas: reescrever o ADR como se a versão antiga nunca tivesse existido, ou registrar uma **emenda** datada em cima dele. Este projeto escolheu emenda, em `docs/decisoes.md`:

```
### Emenda (2026-08-06) — pessoa sem casa passa a ser estado válido

Decisão:
1. O cadastro continua criando uma casa.
2. Casa nunca fica vazia: se quem sai é o único membro, ela é apagada junto.
3. Pessoa sem casa é estado válido — a UI trata casa_ativa nula.
4. A regra do último admin permanece quando há outros membros.

Consequências: substitui "Não existe conta órfã". Toda tela e todo
endpoint precisam tolerar usuário sem casa ativa.
```

Reescrever apagaria a evidência de que a decisão original fazia sentido **no contexto em que foi tomada**. Emendar preserva as duas versões, com data, e deixa explícito o gatilho da mudança — o mesmo espírito de um changelog. O teste `usuário sem casa recebe casa_ativa nula` e o campo `casa_ativa: null` no `UserResource` são a prova em código de que a emenda foi seguida até a ponta.

## 4. Validar o fuso contra a lista real da IANA

```php
'fuso' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
```

**IANA Time Zone Database** é a base de referência que praticamente todo sistema operacional e linguagem usa para saber o que é um fuso horário válido — nomes como `America/Sao_Paulo`, cada um carregando as regras de horário de verão daquela região ao longo da história. `timezone_identifiers_list()` é a função do PHP que devolve essa lista inteira, embutida na linguagem — não é tabela mantida pelo projeto.

Sem essa validação, nada impediria salvar `fuso: "Marte/Olympus"` — a coluna é só uma string. O problema não aparece ao salvar; aparece meses depois, quando o cálculo de lembretes (ADR-008) tentar converter horário nessa casa e travar num identificador inexistente — bug longe de onde a causa foi introduzida. `Rule::in(...)` fecha essa porta na entrada, onde o erro é barato (`422` imediato) em vez de caro (lembrete que nunca dispara).

## 5. Duas guardas na validação: `current_password` e `unique()->ignore()`

`current_password` é regra nativa do Laravel: compara o valor enviado com o hash da senha do usuário **autenticado no momento**. Aparece em `UpdatePasswordRequest` e `DeleteAccountRequest`:

```php
'current_password' => ['required', 'current_password'],  // trocar senha
'password' => ['required', 'current_password'],           // apagar conta
```

Se a pessoa já está logada, por que pedir a senha de novo? Porque "estar logado" não é o mesmo que "ser quem diz ser" o tempo todo — sessão sequestrada, computador destravado numa mesa compartilhada. Nos dois casos, o cookie de sessão sozinho deixa passar. Trocar senha e apagar conta são as ações mais perigosas de uma conta, por isso exigem essa segunda prova mesmo dentro da sessão.

Já `UpdateProfileRequest` valida o e-mail com:

```php
Rule::unique('users')->ignore($this->user()?->id),
```

`unique('users')` sozinho checa "existe alguém com este e-mail?" — e para quem edita o próprio perfil sem mudá-lo, a resposta é sempre "sim: eu". Sem `ignore()`, ninguém salvaria o formulário a menos que trocasse o e-mail, porque o próprio registro conta como "já em uso". `->ignore($this->user()?->id)` faz a regra voltar a dizer o que deveria: "ninguém **além de mim** pode ter este e-mail."

## 6. Regenerar a sessão depois de trocar a senha

```php
$user->save();

if ($request->hasSession()) {
    $request->session()->regenerate();
}
```

`regenerate()` troca o identificador da sessão mantendo os dados — correto depois de evento sensível, para um identificador vazado antes da troca deixar de servir.

O porquê do `hasSession()`: este projeto usa Sanctum em modo SPA — sessão por cookie só existe quando a requisição vem de domínio **stateful**. Uma chamada fora desse contexto (`curl`, teste HTTP sem simular sessão) não tem `session()` no `Request`; chamar sem essa checagem lança `Session store not set on request`. Não é hipotético: foi o erro real ao rodar os testes desta entrega, porque o ambiente de teste do Laravel não monta sessão a menos que peça. `hasSession()` faz o código funcionar nos dois mundos sem quebrar em nenhum.

## 7. BUG 1 — a conta que ressuscitava depois de apagada

O achado mais sério da entrega: o efeito colateral não aparece na resposta HTTP (`204 No Content` normal) — a conta volta a existir por baixo.

A implementação ingênua seria apagar o usuário e só depois deslogar. O código faz o oposto:

```php
// Encerrar a sessão ANTES de apagar: o logout recicla o remember
// token e salva o usuário, e um save() em modelo já apagado vira
// INSERT — a conta ressuscitaria.
Auth::guard('web')->logout();

if ($request->hasSession()) {
    $request->session()->invalidate();
    $request->session()->regenerateToken();
}

$deleteAccount->handle($user);
```

O mecanismo: `Auth::logout()` do Laravel, ao encerrar sessão, gera um novo *remember token* e chama `$user->save()` para gravá-lo — comportamento interno do framework, não deste projeto. Se esse `save()` acontece **depois** de `$user->delete()`, o Eloquent decide entre `UPDATE` e `INSERT` checando se o registro existe — não se ele *existiu*. Sem linha correspondente, `save()` vira `INSERT`: a conta apagada é recriada, com os mesmos dados, um instante depois.

A correção não mexe na lógica, mexe na **ordem**: deslogar primeiro (o `save()` do `remember_token` ainda acha um usuário vivo, é `UPDATE` inofensivo), apagar depois. O teste dedicado cria o usuário já com `remember_token` para garantir que o `save()` do logout de fato aconteça:

```php
it('não ressuscita a conta ao encerrar a sessão', function () {
    $user = User::factory()->create(['remember_token' => 'token-antigo']);
    $this->actingAs($user)->deleteJson('/api/user', ['password' => 'password'])->assertNoContent();

    expect(User::withoutGlobalScopes()->find($user->id))->toBeNull()
        ->and(User::count())->toBe(0);
});
```

A lição vale além deste caso: qualquer operação de framework que **salva um modelo** — logout, eventos, listeners — é risco depois de um `delete()` na mesma requisição. A pergunta a fazer sempre que "apagar X" e "fazer Y com X" convivem numa rota: o que Y faz se tentar salvar X depois de X não existir mais? Nem sempre a resposta está no seu próprio código — aqui, estava dentro do `Auth::logout()` do Laravel.

## 8. Redefinição de senha: o *password broker* do Laravel

O fluxo usa a *facade* `Password`, que embrulha o **password broker** — mecanismo padrão do Laravel para emitir e validar tokens de redefinição. `sendResetLink` gera um token, guarda o **hash** dele (nunca o valor puro) na tabela `password_reset_tokens` com um timestamp, e dispara a notificação `ResetPassword` com o token puro no link. Ao voltar com o link, `Password::reset()` recalcula o hash do token recebido e compara com o guardado — se bater e não tiver expirado (60 minutos padrão), roda o *closure* que troca a senha. Guardar hash em vez do token puro segue o mesmo raciocínio de nunca guardar senha em texto puro: se o banco vazar, não dá a ninguém um reset pronto para usar.

A resposta de `sendLink` é **sempre a mesma**, exista ou não a conta — deliberado, não omissão:

```php
it('responde igual para e-mail inexistente', function () {
    expect($inexistente->status())->toBe($cadastrado->status())
        ->and($inexistente->json('message'))->toBe($cadastrado->json('message'));
});
```

Se a resposta variasse, a rota viraria um **verificador de quem tem cadastro**: bastaria tentar um e-mail e ler a resposta para descobrir se aquela pessoa usa o sistema.

Dois detalhes fecham o fluxo. `ResetPassword::createUrlUsing`, no `AppServiceProvider`, troca a URL padrão (que apontaria para a API) por uma URL da SPA — `.../redefinir-senha/{token}?email=...` — porque quem digita a senha nova é a tela React, não a API; a API só entra depois, no `POST /reset-password` que a tela dispara. E em desenvolvimento, com `MAIL_MAILER=log`, nenhum e-mail sai de verdade — a notificação inteira é escrita em `api/storage/logs/laravel.log`, de onde se pega o link para testar (seção 12).

## 9. BUG 2 — os campos do formulário vinham vazios

`ContaPage` carrega o usuário via `useAuth()` e passa `name`/`email` como props ao formulário. O bug: ao entrar na tela, os campos apareciam vazios, mesmo com dado certo disponível — causado por como `useState` trata o valor inicial:

```tsx
const [name, setName] = useState(nomeInicial)
```

`useState(valorInicial)` só usa esse valor na **primeira renderização** — depois, o React ignora qualquer novo valor ali. Se o pai renderiza uma vez antes de o usuário chegar da API (`user` ainda `null`) e só depois recebe o dado real, o `useState` já travou vazio na primeira vez — a chegada do dado não reabre essa porta.

Três saídas possíveis, com custo diferente:

| Saída | Como funciona | Custo |
|---|---|---|
| Renderizar só depois de carregado | Pai devolve "carregando" e só monta o formulário com dado já pronto | Simples; um retorno antecipado |
| `key` para remontar | `key={dado.id}` força o React a recriar o componente quando o valor muda | Útil quando o dado troca depois de montado |
| Sincronizar com efeito | `useEffect` observa a prop e chama `setName` de novo quando ela muda | Mais código; React desaconselha como primeira opção |

Este projeto escolheu a primeira: `ContaPage` retorna cedo enquanto `user === null`, e o formulário só é montado com o dado real já disponível:

```tsx
// SecaoPerfil inicializa o estado a partir das props, e useState só
// aproveita o valor da PRIMEIRA renderização. Renderizar antes de o
// usuário chegar deixaria os campos vazios para sempre.
if (user === null) {
  return <p className="text-stone-500">Carregando…</p>
}
```

Foi a mais simples para o caso: sem `key` (o usuário logado não troca em runtime nessa tela) nem sincronização manual via efeito (que traria a complexidade de não sobrescrever o que a pessoa já digitou). O achado veio de teste de front: `await screen.findByLabelText('Nome')` espera a UI assentar antes de interagir — contra a versão com bug, o campo aparecia vazio no assert seguinte, porque o dado real só chegava depois do primeiro render do formulário.

## 10. Tabela de endpoints novos

| Método | Rota | O que faz | Quem pode |
|---|---|---|---|
| `PATCH` | `/api/user/profile` | Atualiza nome e e-mail | Qualquer autenticado (o próprio) |
| `PUT` | `/api/user/password` | Troca a senha, exige a atual | Qualquer autenticado (o próprio) |
| `DELETE` | `/api/user` | Apaga a conta, exige a senha atual | Qualquer autenticado (o próprio) |
| `PATCH` | `/api/households/{id}` | Renomeia a casa e/ou troca o fuso | Admin da casa |
| `POST` | `/forgot-password` | Envia (ou finge enviar) o link de redefinição | Público, sem sessão |
| `POST` | `/reset-password` | Efetiva a troca de senha com o token do link | Público, com token válido |

`DELETE /api/households/{id}/members/{id}` (sair da casa) já existia desde a Fatia de membros — o que mudou aqui foi a lógica dentro de `RemoveMember`, não a rota.

## 11. Como testar manualmente

Com sessão de admin já aberta (cookie + CSRF, fluxo completo no doc 02):

```bash
curl -b cookies.txt -X PUT http://localhost:8000/api/user/password \
  -H "X-XSRF-TOKEN: $CSRF" -H "Accept: application/json" \
  -d '{"current_password":"senha-atual","password":"nova-senha-forte-1","password_confirmation":"nova-senha-forte-1"}'
```

Para "esqueci minha senha", sem sessão nenhuma:

```bash
curl -X POST http://localhost:8000/forgot-password \
  -H "Accept: application/json" -d '{"email":"carlos@exemplo.com.br"}'
```

Como `MAIL_MAILER=log` em desenvolvimento, nenhum e-mail sai de verdade — o link fica escrito em `api/storage/logs/laravel.log`. Abrir o arquivo, pegar a URL mais recente da notificação `ResetPassword` (algo como `http://localhost:5173/redefinir-senha/{token}?email=...`) e colar no navegador continua o fluxo pela tela `RedefinirSenhaPage`.

Suíte automatizada:

```bash
php artisan test --filter=AccountTest
php artisan test --filter=PasswordResetTest
php artisan test --filter=HouseholdLifecycleTest
npm test -- ContaPage
```

## 12. A armadilha dos PRs empilhados

Um **PR empilhado** é um Pull Request cuja base não é a `main`, e sim a branch de **outro PR** ainda em revisão — usado quando uma entrega depende do código de outra ainda não mergeada. O GitHub sinaliza isso na tela do PR e, ao mergear o de baixo, reaponta sozinho a base do de cima para a `main`.

O detalhe que pegou esta entrega: o reapontamento **não é instantâneo** — leva alguns segundos. Em 2026-08-07, três PRs empilhados foram mergeados em sequência rápida, sem esperar essa atualização entre um merge e o próximo. Efeito: o segundo foi mergeado **dentro da branch do primeiro** (já apagada pelo squash) em vez de na `main` — o código do segundo e do terceiro nunca chegou lá, apesar de o GitHub mostrar os dois como "mergeados". A recuperação exigiu um PR extra (#32).

Esse PR de recuperação deu conflito **add/add** em arquivos **idênticos** nas duas pontas — contraditório à primeira vista. A explicação está no **squash**: ele não traz os commits originais para a `main`, cria um commit novo com o resultado final, descartando o histórico daquela branch. Se uma branch empilhada nasceu da branch de baixo **antes** do squash dela, o Git perde o ancestral comum entre as duas linhas — cada lado acha que está criando aqueles arquivos do zero, mesmo o conteúdo sendo idêntico, porque não há mais como provar que um descende do outro.

A regra registrada em `docs/fluxo-trabalho.md`:

> Mergeie um PR empilhado de cada vez, e só siga para o próximo depois de conferir na tela dele que a base virou `main`.

E para quando o conflito `add/add` acontecer mesmo assim: resolver tomando a versão da branch empilhada (é o superconjunto) e rodar a suíte inteira para confirmar.

## 13. No mercado

| Prática | Quão comum |
|---|---|
| Regra de negócio corrigida por teste manual, não planejamento antecipado | Universal; "descobrir usando" é etapa normal do ciclo, não falha de processo |
| ADR emendado com data em vez de reescrito | Prática de times maduros com decisões documentadas; a maioria nem mantém ADR |
| Validar fuso contra base IANA (`timezone_identifiers_list()`) | Básico esperado quando fuso é input livre; confiar cego no texto do front é erro comum |
| `current_password` antes de ação sensível | Padrão em produto sério (bancos, GitHub, Google pedem senha de novo); pulado em MVP |
| `Rule::unique()->ignore()` ao editar o próprio registro | Detalhe que todo mundo esquece na primeira vez; depois vira reflexo |
| Regenerar sessão após troca de senha | Recomendado (OWASP Session Fixation); esquecido porque "funciona" sem isso |
| Cuidado com `save()` de framework depois de `delete()` no mesmo fluxo | Ponto cego real — poucos pensam no que código de terceiros salva depois do delete |
| Resposta neutra em "esqueci senha" (sem revelar quem tem cadastro) | Prática conhecida (user enumeration), ainda comum de ver quebrada em produto real |
| `useState(prop)` só pega o valor da primeira render | Pegadinha clássica de quem começa em React; documentada e ainda assim recorrente |
| Cuidado ao mergear PRs empilhados em sequência | Conhecimento tribal; não ensinado em curso introdutório de Git/GitHub |
