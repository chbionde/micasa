# Encerrando o estado depois de excluir uma conta

## O defeito

Excluir a conta funcionava no servidor, mas o frontend continuava na tela autenticada. O console
mostrava `401` no logout e, em novas tentativas, outros `401` no DELETE.

A ordem explicava o comportamento:

1. `DELETE /api/user` apagava a conta e todas as sessões;
2. o frontend chamava `POST /logout`;
3. sem sessão, o servidor corretamente respondia `401`;
4. a exceção interrompia o fluxo antes de limpar usuário e status no React;
5. a tela dizia que a exclusão falhou, embora ela já tivesse acontecido.

## Estado remoto e estado local

Logout comum e exclusão de conta terminam com uma tela pública, mas não são a mesma operação.

No logout comum, o servidor ainda tem uma sessão e precisa invalidá-la. Se a requisição falhar,
o cliente não deve fingir que a sessão acabou.

Depois de excluir a conta, o próprio DELETE já removeu as sessões. Tentar deslogar novamente é
redundante. O cliente deve apenas:

1. limpar o cache do React Query;
2. remover usuário e status autenticado do contexto;
3. navegar para a tela de entrada.

Por isso o `AuthProvider` expõe uma operação local separada. Ela só deve ser usada quando uma
resposta bem-sucedida do servidor já garante que a sessão não existe.

## Respostas atrasadas da sessão antiga

Há uma corrida menos visível: uma atualização de identidade pode ter começado antes da exclusão
e terminar depois dela. Sem proteção, essa resposta antiga colocaria novamente o usuário apagado
no contexto e a aplicação voltaria a considerá-lo autenticado.

O provedor mantém uma geração numérica da sessão. Cada requisição de identidade guarda a geração
em que começou; encerrar a sessão avança o número. Uma resposta só altera o React se ainda
pertencer à geração atual. Assim, não é preciso acoplar cada tela ao cancelamento do Axios, e a
regra vale para todas as chamadas de recarga de identidade.

## Por que limpar o cache

O contexto de autenticação guarda a identidade, mas listas de casas, membros e convites vivem no
React Query. Sem `queryClient.clear()`, esses dados poderiam permanecer na memória e aparecer
momentaneamente se outra pessoa entrasse na mesma aba.

Limpar apenas depois do DELETE é importante: uma senha errada ou bloqueio de regra de negócio
mantém a conta válida, então o estado e o cache também devem permanecer.

## Como reproduzir o teste

```bash
cd web
npm test -- --run src/pages/ContaPage.test.tsx
```

Um teste simula DELETE bem-sucedido e configura o POST de logout para devolver `401`. Antes da
correção, a tela pública não aparecia. Depois, ele comprova um único DELETE, nenhuma chamada de
logout, cache vazio e redirecionamento.

Outro teste deixa uma recarga de identidade pendente, exclui a conta e só então entrega a resposta
antiga. A tela deve continuar pública. Esse controle explícito da ordem das promessas transforma
uma possível corrida intermitente em um teste determinístico.

## Quão comum é essa prática

Separar estado remoto de estado local é comum em SPAs. O erro aparece quando duas ações que têm
o mesmo resultado visual — logout e exclusão — são tratadas como se tivessem o mesmo contrato no
servidor. Limpar caches vinculados à identidade também é prática padrão em logout, troca de
conta e revogação de sessão, evitando mistura de dados entre usuários no mesmo navegador.
