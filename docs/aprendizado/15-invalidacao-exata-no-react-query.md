# Invalidação exata no React Query

## O defeito

Ao sair de uma casa, o backend removia o vínculo corretamente. Mesmo assim, o navegador voltava
a pedir membros e convites daquela casa e recebia `404` ou `500`.

O problema não era a API. A ordem do frontend era:

1. apagar o vínculo;
2. invalidar a consulta de membros;
3. invalidar `['casas']`;
4. somente depois recarregar o usuário e descobrir a nova casa ativa.

Enquanto os passos 2 e 3 rodavam, a tela antiga ainda estava montada.

## Por que `['casas']` alcançou outras consultas

As chaves do React Query são hierárquicas:

```ts
['casas']
['casas', 7, 'membros']
['casas', 7, 'convites']
```

Por padrão, invalidar `['casas']` usa correspondência por prefixo. Isso é útil quando toda uma
árvore mudou, mas era errado neste fluxo: o usuário tinha acabado de perder acesso à casa 7.

A correção usa `exact: true` para atualizar somente a lista de casas:

```ts
queryClient.invalidateQueries({ queryKey: ['casas'], exact: true })
```

Remover outra pessoa continua invalidando exatamente a lista de membros. Sair pessoalmente não
refaz membros nem convites; depois da mutação, o contexto de autenticação recarrega o usuário e
a página mostra a próxima casa ativa ou o estado sem casa.

## Como o teste encontrou a causa

O teste confirma a saída, simula `/api/user` mudando de uma casa ativa para `null` e conta as
chamadas. Antes da correção, membros era buscado três vezes. Depois, membros e convites aparecem
uma vez cada: apenas no carregamento inicial.

Para reproduzir:

```bash
cd web
npm test -- --run src/pages/CasaPage.test.tsx
```

O teste vizinho remove outra pessoa e comprova que esse caso ainda atualiza membros, sem
recarregar usuário ou convites.

## Alternativas rejeitadas

Limpar todo o cache esconderia o defeito, mas causaria mais carregamentos e requisições. Atualizar
manualmente cada objeto no cache também duplicaria regras que pertencem ao servidor. A
invalidação exata preserva o modelo atual e muda somente a abrangência incorreta.

## Quão comum é essa prática

Chaves hierárquicas e invalidação por prefixo são práticas comuns com React Query. O erro também
é comum: uma chave raiz parece representar uma única lista, mas pode selecionar todas as filhas.
Em mutações que removem acesso, vale revisar tanto a ordem das atualizações quanto a abrangência
da chave; refazer uma consulta protegida depois de perder autorização normalmente gera ruído no
console e pode expor tratamento de erro frágil.
