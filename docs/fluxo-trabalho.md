# MiCasa — Fluxo de trabalho no GitHub

## Organização do backlog

- **Milestone = fatia.** Cada fatia do [plano](plano-fatias.md) é um milestone (`Fatia 0`…`Fatia 8`). O progresso da fatia é o progresso do milestone.
- **Issue = tarefa.** Todo trabalho começa numa issue. Fatias futuras existem como uma issue-épico (label `epico`) que é quebrada em issues menores **quando a fatia começa** — não antes, para não especular.
- **Labels:**
  - `tipo:feature` — funcionalidade de produto
  - `tipo:infra` — deploy, backup, CI, ambiente
  - `tipo:docs` — documentação (ADRs, aprendizado)
  - `tipo:bug` — comportamento errado em algo já entregue
  - `epico` — issue guarda-chuva de uma fatia futura
  - `bloqueado` — aguardando algo externo (conta, decisão, outra issue — dito no corpo)
- **Ordem de execução:** a ordem do backlog dentro do milestone é a ordem de cima para baixo da lista de issues; dependências explícitas no corpo ("Depende de #N").

## Fluxo de branches (GitHub Flow)

1. `main` é sagrada: sempre verde, sempre deployável. Feature **nunca** é commitada direto nela.
2. Para cada issue, uma branch a partir da `main`: `tipo/NN-descricao-curta` (ex.: `feature/07-auth-sanctum`, `infra/09-backup-cifrado`). `NN` é o número da issue.
3. Commits na branch seguem **Conventional Commits** (`feat:`, `fix:`, `chore:`, `docs:`, `ci:`, `test:`, `refactor:`), minúsculos, corpo explicando o porquê quando não for óbvio.
4. Terminou: abrir **Pull Request** para `main` com `Closes #NN` na descrição (o merge fecha a issue sozinho). CI verde é pré-requisito para merge.
5. Merge via **squash** (histórico da `main` fica um commit por tarefa, limpo e navegável).
6. Exceção pragmática: correções triviais de documentação podem ir direto na `main` — código, nunca.

## Por que assim (e não Git Flow)

Git Flow (branches `develop`, `release/*`, `hotfix/*`) faz sentido com múltiplos times e releases versionadas. Para um dev solo com deploy contínuo, é burocracia sem retorno. GitHub Flow — branch curta + PR + CI + merge — é o que a maioria das empresas de produto usa hoje e é o fluxo que aparece em entrevista. O PR aqui cumpre três papéis mesmo sem outro revisor humano: roda o CI antes do merge, gera histórico navegável do "porquê" de cada mudança, e monta portfólio público de como você trabalha.
