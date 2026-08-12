# MiCasa — instruções para agentes

Este arquivo é a entrada operacional do Codex. Ele aponta para as fontes versionadas; não
duplique aqui fatos que mudam com frequência.

## Antes de trabalhar

1. Leia `prompt-casa-os.md` para contrato, arquitetura, Definition of Done e anti-padrões.
2. Leia `contexto-do-projeto.md` para ambiente, produção, preferências e lições duráveis.
3. Leia `prompt-continuacao.md` para o estado deixado pela última sessão.
4. Consulte a issue ativa e seus comentários no GitHub. Para estado operacional recente, o
   GitHub e o código vencem os documentos de retomada; corrija documentos desatualizados no
   mesmo trabalho.
5. Leia os ADRs e documentos de domínio citados pela issue antes de decidir arquitetura ou
   alterar regras de negócio.

## Forma de trabalhar

- Sinceridade acima de agradabilidade. Questione decisões ruins e exponha custos e conflitos.
- Segurança vem antes de velocidade, simplicidade ou limitação da VPS. Declare todo trade-off:
  diga o ganho, a perda e o risco residual.
- Verifique antes de afirmar. Estado externo exige fonte consultada nesta sessão; teste verde só
  vale se falharia com o comportamento quebrado.
- Descubra fatos no repositório, sistema ou GitHub antes de perguntar. Quando uma decisão humana
  for necessária, faça uma pergunta por vez, com recomendação e custo das opções.
- Em React/TypeScript, atue como tutor: explique por que o conceito foi usado aqui e qual
  alternativa razoável foi rejeitada.
- Preserve mudanças do desenvolvedor e limite cada entrega ao escopo aprovado. Sugestões extras
  devem aparecer claramente na issue e no PR, nunca escondidas no código.

## GitHub e entrega

- Todo trabalho começa em uma issue necessária e aprovada. Não crie issues ou PRs especulativos.
- Trabalhe em uma issue por vez e em uma branch `tipo/NN-descricao` criada da `main` atualizada.
- Não empilhe PRs dependentes. Um PR mergeado encerra sua branch; trabalho novo usa nova issue ou
  nova branch conforme o escopo.
- Commits seguem Conventional Commits, sem `Co-Authored-By`; o corpo explica o porquê.
- Adicione somente caminhos revisados. Nunca use `git add -A` neste repositório.
- O PR aponta para `main`, inclui `Closes #NN`, registra testes e decisões extras e só deixa de ser
  draft quando a entrega estiver comprovada. CI verde é obrigatório.
- Nunca faça merge. O merge é exclusivo do desenvolvedor.
- Ao terminar tarefa multi-comando, escreva o próximo documento didático em
  `docs/aprendizado/`, para leigo, incluindo como reproduzir e quão comum é a prática no mercado.

## Operação, segurança e comandos para o desenvolvedor

- Antes de manipular segredo, classifique-o como recriável ou insubstituível e avise: não cole o
  valor no chat; o agente não precisa dele.
- Instruções manuais seguem o modelo de `infra/README.md`: o que faz, máquina ou console, comando
  exato, saída esperada e o que fazer se divergir.
- Rode o comando ou leia seu `--help`/cabeçalho antes de entregá-lo. Não use placeholders quando o
  valor já puder ser descoberto.
- Mudanças em `infra/nginx/` não chegam à produção pelo deploy normal. Leia o runbook antes de
  planejar aplicação e verificação.
- Ações destrutivas, produção, credenciais, secrets, publicação, fechamento de issue e outras
  escritas externas exigem escopo explícito. Faça diagnóstico e verificações seguras primeiro.

## Memória do projeto

- Fatos mutáveis — issue ativa, estado de PR/CI, produção e prioridades — vivem no GitHub e devem
  ser medidos novamente. Não os transforme em memória permanente.
- Lições duráveis, decisões e armadilhas ficam nos documentos versionados e passam pelo mesmo
  fluxo de issue/PR que o código.
- Memória nunca contém valores de segredos, tokens, chaves, dados pessoais ou cópias de `.env`.
- A pasta privada de memória do Claude é histórico, não fonte ativa. Não a copie para o Codex;
  promova apenas uma lição ainda verdadeira para os documentos versionados.

## Skills externas

- As regras deste repositório prevalecem sobre qualquer skill. Skill orienta método; não concede
  autorização nem amplia escopo.
- `caveman` só pode ser ativada quando o desenvolvedor pedir explicitamente. Nunca a use em
  segurança, runbooks, ações irreversíveis ou procedimentos de várias etapas.
- Não execute `setup-matt-pocock-skills`: tracker, labels, domínio e ADRs do MiCasa já existem.
- `implement` deve encerrar com a revisão disponível no ambiente e com o checklist de
  `prompt-casa-os.md`; a skill `code-review` do pacote Matt não está instalada de propósito.
- `grilling` respeita a regra local de uma pergunta por vez, mesmo que a skill proponha uma rodada
  com várias perguntas.
- Skills que criam tickets, branches, commits, protótipos, arquivos de ensino, secrets ou outras
  escritas externas só podem fazê-lo quando a solicitação atual autorizar exatamente essa ação.
- Não adote automaticamente `CONTEXT.md`, `docs/adr/`, labels ou branches sugeridos pelas skills.
  Use `docs/modelo-dominio.md`, `docs/decisoes.md`, `docs/fluxo-trabalho.md` e o fluxo existente.

## Verificação proporcional ao risco

- Backend: Pest focado e depois suíte relevante, Pint e Larastan.
- Frontend: Vitest focado e depois suíte relevante, oxlint e `tsc`.
- Shell/nginx/sudoers/deploy: use verificações próprias descritas em `prompt-continuacao.md`; CI de
  PHP/TypeScript não prova infraestrutura.
- Antes de entregar, revise o diff contra a issue, confirme `git diff --check`, registre a
  evidência na issue/PR e declare qualquer validação indisponível.
