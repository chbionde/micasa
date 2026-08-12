# 12 — Varredura de segurança: como se procura buraco no que já está no ar

Este documento conta o que foi feito na issue #43 e, principalmente, **como** foi feito. A
técnica vale mais que o resultado: os buracos desta vez serão outros na próxima.

Pré-requisito: nenhum. Se você nunca ouviu falar de "IDOR" ou "rate limit", os termos são
explicados na primeira vez que aparecem.

---

## 1. O problema de auditar o próprio código

Existe uma armadilha específica em revisar segurança do que você mesmo escreveu: você lê o
código e enxerga **a intenção**. O código diz `$user->isMemberOf($household)` e o seu cérebro
completa "ah sim, isso garante que só membro entra". Mas garante *onde*? Em todas as rotas? Na
rota que foi acrescentada semana passada também?

A varredura desta issue começou por um levantamento de código e terminou com uma conclusão
incômoda: **a leitura sozinha não decide nada.** Ela produz hipóteses. Cada hipótese precisa de
um comando ou de um teste que a confirme ou a derrube.

Foi o que se fez: 25 perguntas viraram 25 testes descartáveis, rodados de uma vez. Dez ficaram
verdes (a defesa existia), quatorze ficaram vermelhas. E duas das vermelhas eram **erro do
teste**, não do sistema — o que é a lição seguinte.

### Regra: quando seu teste falha, suspeite primeiro do teste

Duas sondas falharam esperando HTTP 200 e recebendo 201. Não havia falha nenhuma no sistema: a
API devolve 201 (Created) ao criar recurso, que é o correto. Se aquilo tivesse virado "achado",
teria entrado no relatório uma falsidade com aparência de fato.

O custo de conferir é de um minuto. O custo de não conferir é um relatório que ninguém pode
usar, porque não se sabe quais linhas são verdade.

---

## 2. As perguntas que se fazem

Uma varredura não é "olhar o código procurando coisa errada" — isso não termina nunca e não
cobre nada. É percorrer uma lista de **categorias de falha conhecidas**, perguntando de cada uma
"aqui, especificamente, como isso apareceria?".

### Autorização horizontal (IDOR)

**IDOR** = *Insecure Direct Object Reference*. O nome é feio; a ideia é simples: eu troco um
número na URL e vejo a coisa de outra pessoa.

```
/api/households/7/shopping-lists/42     ← minha casa é a 7. E se a lista 42 for de outra casa?
```

O MiCasa se defende disso em duas camadas independentes:

1. **`scopeBindings()`** na definição das rotas. Sem ele, o Laravel resolve `{shopping_list}`
   como "a lista de id 42", ponto. Com ele, resolve como "a lista de id 42 **que pertence à casa
   da URL**" — e devolve 404 antes de qualquer código da aplicação rodar.
2. **Policies** conferindo `$user->isMemberOf($list->household)`. Repare no detalhe: a casa **do
   recurso**, não a da URL. Conferir a casa da URL seria conferir aquilo que o atacante escolheu,
   o que não confere nada.

Isso já estava certo, e havia teste. O que **não** havia era teste para o caso de dentro: item
da lista A alcançado pela URL da lista B, **na mesma casa**. A defesa funciona (o subgrupo de
rotas herda o `scopeBindings`), mas era uma herança implícita — bastava alguém mover aquele
`->group()` para fora e ninguém saberia. Agora fica vermelho.

**A lição geral:** quando uma proteção depende de herança, aninhamento ou configuração, teste-a
no nível mais profundo. O nível raso passa por acidente.

### Autorização vertical (escalação de privilégio)

Um membro comum consegue se promover a administrador? Consegue rebaixar quem administra?
Consegue o último administrador se rebaixar e deixar a casa sem dono?

As três respostas eram não, e com teste. Este bloco passou limpo.

### Mass assignment

O nome descreve o ataque: eu **atribuo em massa** campos que você não queria que eu escolhesse.

```jsonc
POST /api/households/7/shopping-lists
{ "nome": "Mercado", "household_id": 99, "created_by": 1 }
//                     ↑ e se isso for parar no banco?
```

O MiCasa se defende com `#[Fillable]` enxuto no model e atribuição explícita no controller —
`created_by` é `$request->user()->id`, nunca o que veio no corpo. Estava certo e agora tem
quatro testes provando pelo **efeito**, não pela intenção.

### Enumeração

Enumerar = descobrir *quem existe* sem ter acesso a nada. O cadastro recusa e-mail duplicado,
então "erro de e-mail em uso" responde "esta pessoa tem conta aqui".

Aqui mora uma lição desconfortável: **nem todo achado tem conserto.** Impedir e-mail duplicado
e esconder se um e-mail existe são objetivos incompatíveis. O que dá para fazer é encarecer a
exploração — e era isso que faltava, porque a rota de perfil não tinha limite nenhum.

Registrar honestamente "isto vaza, e vai continuar vazando, mas agora custa caro" é melhor que
fingir que fechou.

---

## 3. Os três achados que se somavam

Os achados de alta severidade quase nunca são independentes. Estes três formavam uma corrente:

```
  Alguém obtém a sessão da vítima
  (aparelho emprestado, cookie roubado)
              │
              ▼
  A1 · troca o e-mail da conta ────────────────► o e-mail era a chave de
      (não pedia senha)                          recuperação da conta
              │
              ▼
  A vítima desconfia e troca a senha
              │
              ▼
  A2 · a sessão do atacante continua viva ─────► a reação natural não
      (troca de senha não derrubava ninguém)     expulsava ninguém
              │
              ▼
  A conta é do atacante, para sempre.
  O "esqueci minha senha" vai para ele.
```

Nenhum dos dois, sozinho, é catastrófico. Juntos, transformam **acesso temporário em posse
permanente** — e o dono legítimo perde inclusive como provar que a conta era dele.

O terceiro, A3, alimentava a entrada da corrente: a política de senha era `min:8` e mais nada,
então `password` e `12345678` eram aceitas. Medido, não suposto.

**A lição:** avalie achados em cadeia, não em lista. A pergunta certa não é "quão grave é este
item?", é "**o que este item permite fazer com aquele outro?**".

---

## 4. As correções, e por que estas e não outras

### A1 — senha para trocar o e-mail

Uma regra condicional no `UpdateProfileRequest`: `current_password` é obrigatório **quando o
e-mail muda**. Trocar o nome continua livre — pedir senha para tudo é a receita para ensinar as
pessoas a digitá-la sem pensar.

### A2 — apagar sessões em vez de instalar um middleware

O Laravel tem o middleware `AuthenticateSession`, que amarra cada sessão ao hash da senha e
derruba todas quando a senha muda. Ele resolveria — e foi **descartado**.

Motivo: ele muda a semântica de sessão do aplicativo inteiro e cobra uma consulta a mais por
requisição, para resolver um problema que, num projeto cujo driver de sessão já é o banco, se
resolve apagando linhas de uma tabela.

```php
DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $atual)->delete();
```

**A lição:** a solução do framework nem sempre é a certa. A pergunta é "quanto de comportamento
global isto muda, e vale o que resolve?".

O custo desta escolha está anotado na própria classe: ela **depende** de `SESSION_DRIVER=database`.
Trocar o driver exige revisitar este ponto. Decisão com custo escondido é dívida; decisão com o
custo escrito ao lado é escolha.

### A3 — comprimento em vez de composição

A política nova é `min(10)` mais confronto com vazamentos conhecidos. Não há exigência de
maiúscula, número e símbolo — **de propósito**.

Regra de composição produz `Senha@123`: longa o suficiente para o formulário aceitar, curta o
suficiente para estar em qualquer dicionário de ataque. O NIST 800-63B desaconselha composição
obrigatória desde 2017, e recomenda exatamente o par usado aqui.

E uma armadilha que só aparece lendo o código do framework:

```php
// Illuminate\Validation\NotPwnedVerifier::search()
} catch (Exception $e) {
    report($e);           // ← rede caiu
}
$body = (isset($response) && $response->successful()) ? $response->body() : '';
// corpo vazio → nenhum vazamento encontrado → a senha PASSA
```

O verificador **falha aberto**, e nasce com timeout de 30 segundos. Numa VPS de 1 GB, isso é
meio minuto de cadastro travado para, no fim, aceitar a senha do mesmo jeito. O timeout foi
rebindado para 3 segundos: se vai passar, que passe rápido.

**A lição:** antes de confiar numa verificação de segurança, descubra o que ela faz **quando
falha**. "Falha aberto" e "falha fechado" são decisões de projeto, e quase nunca estão
documentadas na primeira página.

### A4, A5, A6 — os limites que o framework parou de dar

Até o Laravel 10, o grupo de middleware `api` incluía `throttle:api`, e toda rota de API nascia
com um teto. **A partir do Laravel 11, não inclui mais.** Rota sem limite declarado é rota sem
limite nenhum.

Ninguém foi descuidado: as rotas que têm limite neste projeto o receberam uma a uma, com
critério. O que falhou foi supor que existia um piso por baixo.

Medido: 25 chutes de senha, nenhum 429.

**A lição:** quando um framework sobe de versão maior, o que mudou por baixo não avisa. `php
artisan route:list` mostrando os middlewares de cada rota é um comando de auditoria, não só de
depuração.

O caso do login merece um parágrafo próprio, porque é sutil. O limite existia — 5 tentativas
por par **e-mail + IP**:

```php
Str::lower($this->string('email')).'|'.$this->ip()
```

Isso protege muito bem **uma conta** de ser martelada. E não vê o ataque inverso: **uma senha
comum contra muitas contas** (*password spraying*), em que cada par e-mail+IP é usado uma única
vez e nunca chega perto do limite. Medido: 20 contas, uma tentativa em cada, mesma origem —
nenhum 429.

A correção é somar uma chave por IP. O limite antigo continua, porque é ele que dá a mensagem
útil a quem simplesmente errou a própria senha.

**A lição:** um rate limit responde à pergunta "quantas vezes **esta chave**?". Você só está
protegido contra os ataques que usam a mesma chave repetidas vezes. Desenhe a chave olhando
para o ataque, não para o usuário legítimo.

### A7 — o que sobra quando a conta some

Apagar a conta deixava para trás a linha em `sessions` e o token pendente em
`password_reset_tokens`. Nenhuma das duas tem chave estrangeira que o banco possa cascatear:
`sessions` nasce sem constraint na migration padrão do Laravel, e `password_reset_tokens` é
indexada por e-mail, não por id.

Aqui coube uma correção de severidade, e ela é o exemplo mais claro deste documento sobre
**precisão importar**. A anotação original dizia que a sessão órfã era um risco de autenticação.
Não é: na requisição seguinte o guard procura o usuário, não acha, e a requisição sai anônima.
O que a sessão órfã realmente é: endereço de IP e user agent guardados depois de um pedido
explícito de exclusão de conta. Isso é retenção indevida de dado pessoal — sério, e sério de
outro jeito.

O token de redefinição, esse sim, tem consequência concreta: enquanto vale, o link antigo
redefine a senha de **qualquer conta que venha a existir com aquele e-mail**.

**A lição:** exagerar a gravidade de um achado não é "errar para o lado seguro". É gastar o
crédito do relatório. Quem lê e descobre um exagero passa a descontar tudo.

---

## 5. Modo tutor de React — o campo que aparece

A tela de conta precisou de um campo de senha que **só existe quando o e-mail muda**.

### Valor derivado não é estado

```tsx
const emailMudou = email.trim().toLowerCase() !== emailInicial.toLowerCase()
```

Uma linha, calculada a cada render. A alternativa que costuma sair primeiro é esta:

```tsx
// ✗ não faça
const [emailMudou, setEmailMudou] = useState(false)
useEffect(() => {
  setEmailMudou(email !== emailInicial)
}, [email, emailInicial])
```

O segundo custa um render extra (o `useEffect` roda **depois** da renderização, e o `setState`
dispara outra) e cria uma janela em que `email` já mudou e `emailMudou` ainda diz que não. Dois
fatos sobre a mesma coisa, guardados em lugares diferentes, com chance de discordarem.

**A regra:** se dá para calcular a partir do que você já tem em mãos, calcule. Estado é para o
que o React não consegue deduzir — o que a pessoa digitou, o que o servidor respondeu.

### Renderização condicional é ausência, não invisibilidade

```tsx
{emailMudou && <CampoTexto id="conta-senha-atual" … />}
```

Quando `emailMudou` é `false`, o React não põe **nada** no DOM. O campo não fica escondido por
CSS — ele não existe. A diferença importa para quem navega por teclado (não há parada invisível
no `Tab`) e para leitor de tela (não há campo anunciado sem contexto).

O `&&` funciona porque em JavaScript `false && qualquerCoisa` é `false`, e o React ignora
`false` ao renderizar. Cuidado com o vizinho traiçoeiro: `{lista.length && <Coisa/>}` renderiza
um `0` na tela quando a lista está vazia, porque `0` não é ignorado. Use `length > 0`.

### O rótulo é decisão de acessibilidade

O campo ia se chamar "Senha atual" — o mesmo rótulo da seção de trocar senha, logo abaixo. Dois
campos com o mesmo nome acessível na mesma página deixam quem usa leitor de tela sem saber qual
é qual. Virou "Confirme sua senha".

Isso apareceu porque o teste tentou `getByLabelText('Senha atual')` e teria pegado o campo
errado. **Testar pelo rótulo, como a pessoa enxerga a tela, encontra problema de acessibilidade
de graça** — é o motivo de a Testing Library empurrar você para `getByLabelText` em vez de
`getByTestId`.

---

## 6. Como saber se um teste presta

O projeto já tinha a regra, e ela foi aplicada de forma literal aqui. A pergunta:

> Se o comportamento estivesse quebrado, este teste ficaria vermelho?

Responder "acho que sim" não vale. Dá para **medir**:

```bash
git checkout HEAD~1 -- app/ routes/ config/ phpunit.xml   # volta o código de antes
php artisan test tests/Feature/Security/ContaESessaoTest.php
git checkout HEAD -- app/ routes/ config/ phpunit.xml     # restaura
```

Resultado: **13 dos 20 vermelhos**. Os 7 verdes são testes de controle de propósito — "não
derruba a sessão de outra pessoa", "não limita a leitura no uso normal". Eles não deveriam
mudar de cor, e não mudaram.

Se um teste que você escreveu para provar uma correção continua verde sem a correção, ele não
prova nada. Ele só ocupa espaço e dá confiança falsa.

---

## 7. O que ficou de fora, e por quê

Seis achados seguem abertos, de propósito:

| Achado | Por que não agora |
|---|---|
| **A8** — sem CSP | CSP para SPA do Vite exige lidar com o preload de módulos; errar derruba o site. Merece iteração própria |
| **A9** — sem HSTS | Prende o domínio em HTTPS para todo navegador que já visitou. Num duckdns com Let's Encrypt, uma renovação falha deixaria o site inalcançável sem tela de "prosseguir". Merece rampa |
| **A10** — chave de deploy com poder de root | Usuário novo na VPS, sudoers restrito, rotação de chave, secret do GitHub e um deploy de teste que pode quebrar a publicação |
| **A11, A12, A13** | Pequenos, mas de outro assunto — vão numa segunda PR desta mesma issue |

**Por que publicar estes é seguro e publicar os outros não era:** A8 e A9 qualquer pessoa mede
com `curl -D-` de fora; não são segredo, são medição. A10 só é explorável por quem já roubou o
secret do GitHub. Já A1 a A7 não eram observáveis de fora — exigiam sondar com uma conta. Por
isso foram corrigidos **antes** de qualquer coisa ser publicada.

Esse é o critério, e vale reter: **o que decide se um achado pode ser divulgado não é a
severidade dele, é se ele já está fechado — ou se um estranho já conseguiria vê-lo sozinho.**

---

## 8. Resumo do que levar

1. Ler o código gera hipótese; só comando e teste geram fato.
2. Quando seu teste falha, suspeite dele primeiro.
3. Avalie achados em cadeia. O perigo mora na combinação.
4. Antes de confiar numa verificação, descubra o que ela faz quando falha.
5. Rate limit protege contra repetição **da mesma chave**. Desenhe a chave olhando o ataque.
6. Não exagere severidade — o exagero gasta o crédito do relatório inteiro.
7. Em React: se dá para calcular, não guarde em estado.
8. Prove que seus testes ficam vermelhos, revertendo o código e rodando.
9. Registre o que a varredura **não** cobriu. É onde a próxima começa.
