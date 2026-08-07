# Aprendizado 07 — TanStack Query: cache de servidor e as telas da Casa

> O que foi construído na issue #19: a tela **Casa** (`CasaPage`) com lista de membros, painel de convites e seletor de casa ativa, mais o fluxo de aceitar convite. Tecnicamente é "mais uma fatia de front". Didaticamente é o documento mais importante da série, porque documenta a segunda metade do ADR-006: depois de escrever busca de dados à mão (doc 03, `AuthContext.tsx`), agora entra a biblioteca que resolve o mesmo problema — TanStack Query. O `AuthContext` continua manual **de propósito**, como referência viva do que a biblioteca substitui. Leia com os dois arquivos abertos: `web/src/features/auth/AuthContext.tsx` e `web/src/features/households/hooks.ts`.

---

## 1. O que esta entrega faz

- `CasaPage` — tela da casa ativa: nome, seu papel, lista de membros, painel de convites (só para admin).
- `ListaMembros` — lista quem mora na casa; admin troca papel e remove; qualquer um sai da própria casa.
- `PainelConvites` — admin gera link de convite (aparece uma vez só) e revoga convites pendentes.
- `SeletorCasa` — troca a casa ativa no cabeçalho; só aparece com mais de uma casa.
- `AceitarConvitePage` — quem recebeu o link aceita o convite e entra na casa.

Nenhuma dessas telas inventa a própria busca de dados. Todas passam por `web/src/features/households/hooks.ts`, que embrulha `api.ts` (as chamadas HTTP puras) em `useQuery`/`useMutation` do TanStack Query.

## 2. Cache de servidor: por que não é só "outro `useState`"

`useState` guarda **estado do componente** — algo que só existe porque a interface decidiu existir (o texto digitado, se um modal está aberto). Ninguém além do React sabe desse valor, e ele nasce e morre com o componente.

Os membros de uma casa não são assim. Eles vivem no banco de dados do Laravel; o que a tela tem é **uma cópia** que pode ficar desatualizada no instante seguinte — outra aba muda um papel, outra pessoa remove um membro. Chamar isso de "estado do app" é a raiz do problema que o `useEffect` manual do doc 03 resolve na marra: buscar, guardar em `useState`, e torcer para lembrar de rebuscar toda vez que algo mudar.

TanStack Query nomeia essa categoria como **cache de servidor** (*server state*) e trata como um problema diferente de estado de UI: tem dono (o servidor), pode ficar velho (*stale*), pode ser compartilhado entre telas (duas telas que pedem os mesmos membros usam a mesma cópia), e pode ser marcado como "preciso rebuscar" sem que ninguém precise adivinhar o valor novo. É essa diferença de categoria — não de sintaxe — que separa `useState` de `useQuery`.

## 3. O antes e o depois

**Antes (`AuthContext.tsx`, doc 03) — busca à mão:**

```tsx
const [user, setUser] = useState<User | null>(null)
const [status, setStatus] = useState<AuthStatus>('carregando')

useEffect(() => {
  let ativo = true
  api.get<Envelope<User>>('/api/user')
    .then((res) => { if (ativo) { setUser(res.data.data); setStatus('autenticado') } })
    .catch(() => { if (ativo) { setUser(null); setStatus('visitante') } })
  return () => { ativo = false }
}, [])
```

**Depois (`hooks.ts`, esta entrega) — TanStack Query:**

```tsx
export function useMembros(casaId: number | undefined) {
  return useQuery({
    queryKey: chaves.membros(casaId ?? 0),
    queryFn: () => api.buscarMembros(casaId!),
    enabled: casaId !== undefined,
  })
}
```

E o consumo em `ListaMembros.tsx`:

```tsx
const { data: membros, isPending, isError } = useMembros(casaId)
if (isPending) return <p>Carregando membros…</p>
if (isError) return <p role="alert">Não foi possível carregar os membros.</p>
```

Onze linhas de `useEffect`/`useState`/flag viraram uma chamada de hook. A tabela abaixo diz exatamente o que a biblioteca passou a fazer sozinha:

| Tarefa que o `AuthContext` escreve à mão | O que o `useQuery` faz sozinho |
|---|---|
| `useState` separado para o dado e para o status (`carregando`/`autenticado`/`visitante`) | `isPending` / `isError` / `isSuccess` prontos, derivados do estado interno da query |
| Flag `ativo` + `return () => { ativo = false }` no `useEffect`, para não gravar estado de um componente que já saiu de cena | A query rastreia sozinha se ainda está "atual"; uma resposta que chega depois de a query ter sido superada é descartada sem precisar de flag nenhuma. Se a função de busca aceitasse o `AbortSignal` que o TanStack Query oferece (as funções em `api.ts` hoje não usam esse parâmetro), a própria requisição de rede seria cancelada — aqui o que já ganhamos é o descarte da resposta, não o cancelamento da chamada em si |
| Nenhum cache: cada componente que monta refaz o fetch do zero | Cache por `queryKey`: duas telas que chamam `useCasas()` compartilham a mesma cópia, sem duplicar requisição |
| Nenhum refetch depois do primeiro carregamento | Refetch automático ao a aba voltar a ficar em foco (comportamento padrão) |
| Nenhuma retentativa se a rede falhar uma vez | `retry` configurável (`1` em produção, `0` nos testes — seção 9) |
| Repetir esse bloco inteiro em cada componente que precisa de dado remoto | Um hook reutilizável (`useQuery`), chamado onde for preciso |

O `AuthContext` não foi migrado porque o ADR-006 pede exatamente isso: sentir o problema à mão antes de trocar pela ferramenta. Ele fica como está — é material de estudo, não dívida técnica.

## 4. `queryKey`: o endereço do dado no cache

Cada resultado de `useQuery` fica guardado num mapa interno, indexado pela `queryKey`. Pense nela como o endereço do dado — quem pede a mesma chave recebe a mesma cópia; quem invalida uma chave marca aquele endereço específico como velho.

`hooks.ts` centraliza todas as chaves num único objeto:

```tsx
export const chaves = {
  casas: ['casas'] as const,
  membros: (casaId: number) => ['casas', casaId, 'membros'] as const,
  convites: (casaId: number) => ['casas', casaId, 'convites'] as const,
}
```

O comentário no próprio código explica o motivo: **"evita o erro clássico de invalidar com uma string e ler com outra"**. Sem essa centralização, seria fácil um hook escrever `['casa', casaId, 'membros']` (singular) e outro ler `['casas', casaId, 'membros']` (plural) — duas chaves parecidas, duas entradas de cache diferentes. A invalidação bateria numa e a tela leria da outra: nada quebra, nenhum erro aparece, a lista simplesmente fica velha para sempre e ninguém percebe, porque visualmente a tela carregou normal. É o tipo de bug que só aparece em produção, semanas depois, quando alguém pergunta "por que esse dado não atualiza". Usar uma função (`chaves.membros(casaId)`) em vez de repetir o array na mão em cada hook é o que fecha essa porta.

## 5. `useQuery` × `useMutation`: ler x escrever

`useQuery` é para **ler**: busca ao montar, guarda em cache, expõe `data`/`isPending`/`isError`. `useMutation` é para **escrever** (criar, alterar, apagar): não busca sozinho, só executa quando você chama `.mutate(...)`, e não tem cache próprio — o resultado dele normalmente serve para invalidar o cache de alguma query (seção 6).

```tsx
export function useAlterarPapel(casaId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ membroId, papel }: { membroId: number; papel: 'admin' | 'member' }) =>
      api.alterarPapel(casaId, membroId, papel),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: chaves.membros(casaId) }),
  })
}
```

Dois detalhes de `useQuery` que aparecem em toda a entrega:

- **`enabled`** — controla se a query roda. `useMembros` só busca quando `casaId !== undefined`; sem casa ativa, não existe o que buscar, e a query fica parada em vez de disparar uma requisição fadada a falhar. `useConvites` soma uma segunda condição (`habilitado`), porque `PainelConvites` só é renderizado para quem é admin — mas o `enabled` é uma trava extra, não a única.
- **`isPending`/`isError`** — em vez de um booleano `carregando` escrito à mão, o hook já devolve o status da requisição. `ListaMembros` usa os dois para decidir entre "Carregando…", a mensagem de erro com `role="alert"` e a lista de fato.

## 6. Invalidação de cache: em vez de adivinhar, rebuscar

O jeito ingênuo de atualizar a tela depois de uma mutação seria calcular à mão o novo estado — "removi o membro 5 da lista local". Funciona até divergir do servidor (outro admin mexeu ao mesmo tempo, a resposta trouxe um campo derivado que você não recalculou certo). `invalidateQueries` evita esse cálculo: em vez de adivinhar, marca a chave como velha e deixa o React Query rebuscar do servidor — que é a única fonte da verdade sobre "quem mora na casa agora".

Os casos reais no código, cada um proporcional ao que a ação realmente muda:

- **Mudar papel** (`useAlterarPapel`) invalida só `chaves.membros(casaId)` — a lista de membros mudou, mais nada.
- **Sair da casa / remover membro** (`useRemoverMembro`) invalida `membros` **e** `casas` — sair muda quem está na lista de membros *e* muda a lista de casas de quem saiu.
- **Trocar de casa ativa** (`SeletorCasa.aoTrocar`) chama `queryClient.invalidateQueries()` sem `queryKey` — invalida **tudo**. Faz sentido: ao trocar de casa, todo dado que dependia da casa anterior (membros, convites, o resto que vier depois) está potencialmente errado, e listar cada chave uma por uma seria repetir a mesma lógica em outro lugar.
- **Aceitar convite** (`AceitarConvitePage`) também invalida tudo, pelo mesmo motivo: a pessoa passou a pertencer a uma casa nova.

## 7. `staleTime`: por quanto tempo o dado é considerado fresco

`staleTime` é o tempo que um dado recém-buscado continua "fresco" — dentro dessa janela, montar o mesmo componente de novo (ou focar a aba) **não** dispara nova requisição; usa o que já está em cache. Passada a janela, o dado vira "velho" (*stale*) e o próximo gatilho (foco na aba, nova montagem) rebusca.

`main.tsx` configura `staleTime: 30_000` (30 segundos) para toda a aplicação:

```tsx
const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
})
```

O trade-off, comentado no próprio arquivo: o padrão do TanStack Query é `staleTime: 0`, que rebusca a cada foco na aba — em uma casa com várias pessoas trocando de tela o tempo todo, isso vira rajada de requisições. 30 segundos absorve esse vaivém sem deixar a lista "velha na cozinha" por muito tempo. É uma escolha deliberada, não o valor de fábrica.

## 8. Retorno pós-login: não perder o convite pelo caminho

Cenário real: alguém recebe um link de convite (`/convite/TOKEN`) sem estar logado. A rota exige sessão (está sob `RequireAuth` no `router.tsx`), então a pessoa é redirecionada para `/login` — mas se o redirecionamento sempre mandasse para `/` depois do login, o convite se perderia no meio do caminho.

`RequireAuth` guarda de onde a pessoa veio antes de redirecionar:

```tsx
if (status === 'visitante') {
  return <Navigate to="/login" state={{ de: location.pathname }} replace />
}
```

`LoginPage` lê esse estado e volta para lá, em vez de para `/`, assim que o login é bem-sucedido:

```tsx
const destino = (location.state as { de?: string } | null)?.de ?? '/'
// ...
await login(email, password)
navigate(destino, { replace: true })
```

`location.state` é dado que o React Router carrega junto da navegação (não aparece na URL, some se a página for recarregada) — exatamente o suficiente para esse repasse de "para onde eu ia mesmo". Sem `de`, o padrão é `/`, o comportamento de sempre para quem chegou direto no login.

## 9. Renderização condicional por papel: UX, não segurança

`CasaPage` calcula `souAdmin` a partir do papel do usuário na casa ativa e usa isso para decidir o que aparece:

```tsx
const souAdmin = casa.meu_papel === 'admin'
// ...
<ListaMembros casaId={casa.id} souAdmin={souAdmin} />
{souAdmin && <PainelConvites casaId={casa.id} />}
```

`ListaMembros` usa a mesma flag para trocar o `<select>` de papel por um texto fixo, e `PainelConvites` inteiro só é renderizado para admin. É uma decisão só de interface — **esconder o botão não impede ninguém de chamar a API direto**. Quem tenta `PATCH /api/households/1/members/2` sem ser admin esbarra na `HouseholdPolicy` do backend (doc 06), que valida de novo, do zero, a cada requisição, sem confiar em nada que o front decidiu mostrar ou esconder. `souAdmin` existe para poupar quem tem permissão de ver controles inúteis — a permissão de verdade mora inteiramente no servidor.

## 10. Testes com provedores: por que cada teste pede um `QueryClient` novo

Testar um componente que usa `useQuery` exige um `QueryClientProvider` na árvore — sem ele, o hook não tem onde guardar cache e o teste quebra na hora. `web/src/test/utils.tsx` centraliza isso:

```tsx
export function novoQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, staleTime: 0 },
      mutations: { retry: false },
    },
  })
}

export function renderComRotas(rotas: RouteObject[], entrada: string) {
  const router = createMemoryRouter(rotas, { initialEntries: [entrada] })
  return render(
    <QueryClientProvider client={novoQueryClient()}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>,
  )
}
```

Dois pontos que o comentário do arquivo já resume, e vale destrinchar:

- **`QueryClient` novo por teste, não um compartilhado.** O cache do TanStack Query vive dentro do `QueryClient`. Se todos os testes reaproveitassem a mesma instância, o cache de um teste vazaria para o próximo — o teste B poderia enxergar, instantaneamente e sem chamar o mock, um dado que o teste A deixou guardado sob a mesma `queryKey` (por exemplo, `chaves.casas`, que não depende de nenhum id). O teste passaria, mas por acidente de ordem de execução, não porque o componente está certo.
- **`retry: false`.** O padrão do TanStack Query tenta de novo uma requisição que falhou antes de desistir. Num teste que força um erro (`apiPatch.mockRejectedValue(...)`) e espera a mensagem aparecer, `retry` ligado faria o hook tentar de novo (com atraso crescente) antes de marcar `isError`, e o teste teria que esperar por tentativas que não fazem sentido num ambiente sem rede de verdade. Desligar `retry` faz o erro aparecer na primeira falha, do jeito que o teste espera.

## 11. Um detalhe de teste que vale ouro: esperar a busca terminar antes de afirmar ausência

Em `SeletorCasa.test.tsx`, o teste "não aparece quando a pessoa tem uma casa só" faz algo que parece redundante à primeira vista:

```tsx
it('não aparece quando a pessoa tem uma casa só', async () => {
  mockar([CASA_A])
  renderSeletor()

  await vi.waitFor(() => expect(apiGet).toHaveBeenCalledWith('/api/households'))
  expect(screen.queryByLabelText('Casa ativa')).not.toBeInTheDocument()
})
```

Por que esperar a chamada acontecer antes de checar que o seletor não está na tela? Porque `SeletorCasa` também retorna `null` **enquanto a busca ainda não respondeu** — a linha é `if (casas === undefined || casas.length < 2) return null`. Sem o `waitFor`, a asserção `not.toBeInTheDocument()` rodaria no primeiro render, com `casas` ainda `undefined` (a requisição sequer terminou) — e passaria. Só que passaria pelo motivo errado: não porque "uma casa só esconde o seletor", mas porque "nada carregou ainda". Um bug real — por exemplo, mostrar o seletor também com uma casa — não seria pego por esse teste, porque ele nunca chega a checar o estado depois dos dados chegarem. É um **falso positivo**: teste verde, comportamento não verificado. Esperar explicitamente a chamada à API (`vi.waitFor`) garante que a asserção de ausência é feita depois que o componente teve a chance de aparecer, se fosse para aparecer.

## 12. Como testar manualmente

O roteiro completo (registrar, gerar convite, aceitar em outra conta, trocar papel, ver o seletor de casa aparecer com duas casas) está em [`docs/como-executar-e-testar.md`](../como-executar-e-testar.md), seção "Roteiro para testar a Fatia 1". Resumo em duas linhas: suba API e front (`php artisan serve` + `npm run dev`), registre duas contas em janelas diferentes e use o link de convite gerado por uma para entrar com a outra.

## 13. No mercado

| Prática desta entrega | Quão comum no mercado |
|---|---|
| TanStack Query (ou equivalente, como SWR) para dados de servidor | Padrão de fato hoje em React; vaga que descreve "consumo de API" majoritariamente espera uma lib de server state, não `useEffect` manual em produção |
| Substituiu boa parte do uso de Redux | Redux nasceu para qualquer estado global, inclusive dado de API; hoje o pedaço "dado remoto com cache/loading/erro" migrou quase inteiro para libs como esta, sobrando Redux (ou Context) para estado de UI genuinamente client-side |
| `queryKey` centralizada num objeto/factory | Prática recomendada pela própria documentação da lib e comum em times maduros; evitar string solta espalhada pelo código é o que evita o bug da seção 4 |
| Invalidação (`invalidateQueries`) como caminho padrão, sem update otimista manual | O caminho mais simples e mais usado no dia a dia; atualização otimista do cache existe na lib, mas é reservada para UX que exige resposta instantânea antes da confirmação do servidor |
| `staleTime` ajustado deliberadamente por aplicação | Comum; manter o default (`0`) sem pensar é o que gera a "rajada de requisições" que o comentário do `main.tsx` evita |
| `enabled` para queries condicionais | Uso básico esperado; deixar uma query disparar sem os dados necessários (e falhar) é erro comum de quem começou a usar a lib |
| `QueryClient` novo por teste + `retry: false` | Recomendação oficial da própria documentação do TanStack Query para testes; pular isso é fonte recorrente de teste instável ("funciona sozinho, falha na suíte inteira") |
