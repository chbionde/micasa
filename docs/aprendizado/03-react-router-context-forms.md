# Aprendizado 03 — A SPA ganha corpo: Router, Context, formulários controlados e Tailwind

> O que foi construído na issue #2, conceito por conceito. Este é o documento mais denso da série até agora, porque é aqui que o React de verdade começa. Leia com o código aberto do lado.

---

## 0. Como rodar o app inteiro na sua máquina

```bash
# Terminal 1 — API
cd api && php artisan serve          # Laravel em http://localhost:8000

# Terminal 2 — front
cd web && npm run dev                # Vite em http://localhost:5173
```

Abra `http://localhost:5173`: você cai no login, cria uma conta, e a sessão por cookie (doc 02) faz o resto.

---

## 1. Componente e JSX — o alfabeto do React

Um componente é **uma função que recebe dados e devolve interface**:

```tsx
export function DashboardPage() {
  const { user } = useAuth()
  return <h1>Olá, {user?.name}</h1>
}
```

Aquilo que parece HTML dentro do JavaScript chama-se **JSX**. Não é string: o compilador transforma em chamadas de função, e as `{chaves}` interpolam qualquer expressão JS. A página inteira é uma árvore de componentes chamando componentes — `router` → `RequireAuth` → `AppLayout` → `DashboardPage`.

**Props** são os parâmetros dessa função. O `CampoTexto` recebe `label`, `value`, `onChange`… e quem decide o que fazer é o pai. Dados descem por props; eventos sobem por callbacks. Essa via de mão única é **o** modelo mental do React.

## 2. `useState` e formulários controlados — o exercício manual do ADR-006

```tsx
const [email, setEmail] = useState('')
// ...
<input value={email} onChange={(e) => setEmail(e.target.value)} />
```

**Estado** é a memória do componente entre renderizações. `useState` devolve o valor atual e a função que o troca — e trocar o estado **re-renderiza** o componente (a função roda de novo com o valor novo).

Num **formulário controlado**, o input não é dono do próprio texto: cada tecla dispara `onChange`, que grava no estado, que re-renderiza o input com o valor vindo do estado. O React vira a fonte única da verdade — validar, limpar ou preencher programaticamente fica trivial. A alternativa (input "não-controlado", lendo o valor só no submit) existe e é mais simples, mas você perde reação em tempo real. **Pergunta de entrevista clássica: a diferença entre os dois.** Escrevemos os formulários à mão exatamente para você sentir a fiação que o React Hook Form vai abstrair na Fatia 3.

## 3. `useEffect` — efeitos fora da renderização

Renderizar deve ser puro (dados → tela, sem tocar no mundo). Buscar o usuário na API é um **efeito colateral**, e efeito mora no `useEffect`:

```tsx
useEffect(() => {
  let ativo = true
  api.get<User>('/api/user')
    .then((res) => { if (ativo) setUser(res.data) })
    .catch(() => { if (ativo) setStatus('visitante') })
  return () => { ativo = false }   // cleanup: roda quando o componente sai
}, [])                             // [] = roda uma vez, ao montar
```

A flag `ativo` evita gravar estado num componente que já desmontou (resposta chegando atrasada). O `<StrictMode>` monta tudo duas vezes em dev **de propósito** para caçar efeitos mal escritos — se você ver a requisição dupla no DevTools, é ele trabalhando. Este padrão manual é justamente o que o TanStack Query automatiza; você o escreveu uma vez, como combinado no ADR-006.

## 4. Context — estado que a árvore toda enxerga

O usuário logado é necessário no header, no dashboard, nas guardas… Passar por props através de cinco níveis ("prop drilling") acopla todo mundo no caminho. **Context** é o túnel: o `AuthProvider` publica `{ user, status, login, logout }` e qualquer descendente lê com `useAuth()`, sem intermediários.

```tsx
<AuthProvider>            {/* publica */}
  <RouterProvider … />    {/* qualquer página lê com useAuth() */}
</AuthProvider>
```

`useAuth` é um **hook customizado** — função que usa outros hooks e embala uma lógica reutilizável. O `throw` dentro dele transforma "esqueci o Provider" num erro claro em vez de um `null` misterioso. **Quando NÃO usar context:** estado local de uma tela (o texto do campo de e-mail não interessa a mais ninguém — fica no `useState` da página). Context global para tudo é o erro clássico de iniciante.

## 5. React Router — a SPA com várias telas

> **Nota (2026-08-07):** a rota `/login` mostrada abaixo passou a se chamar `/entrar`. Em produção a SPA e a API compartilham o mesmo endereço, e `GET /login` (tela) colidia com `POST /login` (autenticação do Laravel). O motivo completo está no [doc 09, seção 7](09-vps-oracle-e-deploy.md) e no ADR-020. A **chamada de API** continua sendo `/login` — o que mudou foi só o endereço da tela.

Numa SPA o servidor não navega; o **React Router** troca componentes conforme a URL, sem recarregar a página:

```tsx
export const router = createBrowserRouter([
  { element: <GuestOnly />,   children: [{ path: '/login', element: <LoginPage /> }, …] },
  { element: <RequireAuth />, children: [{ element: <AppLayout />, children: [{ path: '/', element: <DashboardPage /> }] }] },
])
```

Conceitos usados:
- **Rotas aninhadas + `<Outlet/>`:** a rota-pai renderiza a casca e o `<Outlet/>` é o buraco onde o filho aparece. `RequireAuth` e `AppLayout` são cascas.
- **Guarda de rota:** `RequireAuth` decide — carregando? mostra spinner; visitante? `<Navigate to="/login"/>`; autenticado? `<Outlet/>`. Proteção declarada uma vez, herdada por todos os filhos.
- **`<Link>` vs `<a>`:** o `<a>` recarrega a página inteira (mata o estado da SPA); o `<Link>` só troca a rota.
- **`useNavigate`:** navegação programática (depois do login bem-sucedido, `navigate('/')`).

## 6. Tailwind — o CSS da entrega

Tailwind é CSS **utilitário**: em vez de inventar nomes de classe e mantê-los num arquivo separado, você compõe classes prontas no próprio JSX (`flex`, `px-4`, `rounded-lg`). Parece feio no primeiro dia e produtivo no terceiro — o estilo mora ao lado da estrutura, e o build elimina tudo que não foi usado. **Mobile-first é nativo:** as classes sem prefixo valem para telas pequenas, e prefixos como `md:` adicionam comportamento em telas maiores — exatamente a prioridade do MiCasa (celular, em pé, na cozinha). A alternativa (CSS Modules, styled-components) é válida; Tailwind ganhou o mercado por velocidade e consistência, e aparece nominalmente em muitas vagas.

## 7. Os testes — mock de módulo e roteador de memória

Testar página que depende de API e de rota exige dublês:

- **`vi.mock('../lib/api')`** troca o axios real por funções falsas — o teste decide o que a "API" responde (422 com erros? usuário válido?), sem rede.
- **`createMemoryRouter`** roda o roteador em memória, sem navegador — dá para começar o teste "em /login" e afirmar que, após o login, o conteúdo da rota `/` apareceu.
- O teste de erro afirma que a mensagem do servidor aparece **via `role="alert"`** — acessibilidade e teste andando juntos de novo.

## 8. A lição do aviso do lint (fast refresh)

O oxlint avisou: arquivo que exporta componente **e** outras coisas quebra o *fast refresh* (a mágica do Vite de trocar o código editado sem perder o estado da tela). Separamos: `auth-context.ts` (contexto + tipos + hook) e `AuthContext.tsx` (só o componente Provider). Aviso de lint bom é aula grátis de organização.

## 9. Nota de segurança da entrega

O `npm audit` acusou vulnerabilidade alta no React Router — lida com atenção: ela afeta o **modo RSC/framework** (server actions), que não usamos; nosso modo é biblioteca, client-side. O "fix" automático faria downgrade. Decisão registrada: manter a versão, monitorar. Lição: audit se **lê**, não se obedece cegamente — nem se ignora.

## 10. No mercado

Tudo desta entrega é o feijão-com-arroz cobrado em vaga júnior/pleno de React: componentes, props vs estado, formulário controlado, `useEffect` com cleanup, context vs prop drilling, rotas protegidas, testes com mocks. Se você souber **explicar as decisões deste PR**, você cobre a maior parte de uma entrevista técnica de front introdutória.
