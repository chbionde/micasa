# Migração do Claude Code para o Codex

## O que foi feito

O MiCasa deixou de depender da memória privada e das skills do Claude Code. A migração criou
duas camadas diferentes, porque elas têm responsabilidades diferentes:

1. `AGENTS.md`, versionado no repositório, carrega as regras específicas do MiCasa em toda nova
   sessão do Codex.
2. `~/.codex/skills/`, fora do repositório, guarda métodos reutilizáveis de trabalho, como TDD,
   diagnóstico, modelagem de domínio e refatoração segura.

Essa separação evita transformar uma skill genérica em fonte da verdade sobre o projeto. A skill
diz **como** executar um tipo de trabalho; o repositório diz **o que** o MiCasa exige e qual é o
estado atual.

## Por que não copiamos a memória do Claude

As oito memórias privadas do Claude já tinham divergências verificáveis. Alguns exemplos eram o
usuário antigo de deploy, o próximo número do documento de aprendizado e a afirmação de que a
pasta `publicar/` ainda existia.

Copiar esses arquivos criaria uma memória aparentemente útil, mas invisível ao Git e sem revisão
por PR. Esse foi exatamente o modo de falha que os documentos `contexto-do-projeto.md` e
`prompt-continuacao.md` foram criados para eliminar.

A regra adotada é:

- estado mutável, como issue ativa, CI e produção, é consultado novamente no GitHub ou sistema;
- lição durável é registrada em arquivo versionado;
- segredo nunca entra em memória, documentação ou issue pública;
- memória antiga é histórico, não fonte ativa.

## Como o Codex encontra o `AGENTS.md`

Segundo a [documentação oficial do Codex sobre `AGENTS.md`](https://developers.openai.com/codex/guides/agents-md),
o agente lê esses arquivos antes de trabalhar. A descoberta começa pelas instruções globais e
percorre o repositório até o diretório atual; arquivos mais próximos podem especializar as regras.
A cadeia é reconstruída ao iniciar uma nova sessão.

Por isso o `AGENTS.md` da raiz é curto. Ele contém as regras que precisam estar sempre visíveis e
ponteiros com condições claras para documentos maiores. Copiar todo o `prompt-casa-os.md` para ele
gastaria contexto em toda interação e criaria duas cópias para manter.

## Skills instaladas

Foram selecionadas 31 skills. Cada repositório foi fixado por SHA de commit para que uma
reinstalação futura receba os mesmos bytes, mesmo que a branch `main` mude.

### JuliusBrussee/caveman

Fonte fixada no commit
[`099327780ef69ad88c4cfc15c54314579ac367a4`](https://github.com/JuliusBrussee/caveman/commit/099327780ef69ad88c4cfc15c54314579ac367a4):

- `caveman`
- `investigate-first`
- `lean-build`
- `migration`
- `safe-refactor`
- `surgical-patch`
- `verify-and-stop`

Somente as skills foram copiadas. O CLI, proxy, hooks, MCP e binários do Caveman não foram
instalados. A skill de estilo `caveman` também não fica ativa por padrão no MiCasa, porque sua
compressão conflita com runbooks detalhados e comunicação de segurança.

### mattpocock/skills

Fonte fixada no commit
[`84fdeffd12f2ee307994d1eb6feb48173b6e0502`](https://github.com/mattpocock/skills/commit/84fdeffd12f2ee307994d1eb6feb48173b6e0502):

- `ask-matt`, `codebase-design`, `diagnosing-bugs`, `domain-modeling`
- `grill-with-docs`, `implement`, `improve-codebase-architecture`, `prototype`
- `research`, `resolving-merge-conflicts`, `setup-matt-pocock-skills`, `tdd`
- `to-spec`, `to-tickets`, `triage`, `wayfinder`, `wizard`
- `grill-me`, `grilling`, `handoff`, `teach`, `to-questionnaire`, `wait-what`
- `writing-for-agents`

Não foram instaladas as pastas `misc` e `in-progress`. `code-review` também ficou de fora porque o
ambiente já possui revisão e o MiCasa tem checklist próprio. A skill `implement` menciona essa
dependência; o `AGENTS.md` manda substituí-la pela revisão disponível e pelo checklist do projeto.

`setup-matt-pocock-skills` foi instalada para manter o conjunto promovido completo, mas não deve
ser executada aqui. Ela criaria convenções paralelas de tracker, labels, `CONTEXT.md` e ADRs, apesar
de o MiCasa já possuir `docs/fluxo-trabalho.md`, `docs/modelo-dominio.md` e `docs/decisoes.md`.

## Auditoria anterior à instalação

Todos os 86 arquivos pertencentes aos 31 diretórios selecionados foram lidos antes da cópia,
incluindo `SKILL.md`, `agents/openai.yaml`, modelos de shell e referências Markdown.

A auditoria encontrou estes efeitos que precisam das regras locais:

- `grilling` sugere várias perguntas por rodada; no MiCasa continua valendo uma pergunta por vez;
- `prototype` pode criar branch descartável, mas o MiCasa trabalha com uma issue e um PR por vez;
- `to-spec`, `to-tickets`, `triage` e `wayfinder` podem escrever no GitHub;
- `wizard` pode escrever `.env`, GitHub Secrets e scripts;
- `teach` cria uma estrutura própria de documentos;
- `resolving-merge-conflicts` termina merge ou rebase em andamento;
- `implement` cria commit ao final.

Esses comportamentos não são permissões. Só podem acontecer quando a solicitação atual os inclui
e quando respeitam o fluxo do MiCasa. A regra de precedência está no `AGENTS.md`.

## Como reinstalar as mesmas versões

Execute no **WSL**, em qualquer diretório. Esses comandos escrevem somente em
`~/.codex/skills/`. O instalador interrompe se um diretório de destino já existir; ele não
sobrescreve silenciosamente uma instalação atual.

### 1. Instalar as sete skills do Caveman

```bash
python3 ~/.codex/skills/.system/skill-installer/scripts/install-skill-from-github.py \
  --repo juliusbrussee/caveman \
  --ref 099327780ef69ad88c4cfc15c54314579ac367a4 \
  --path \
    skills/caveman \
    skills/investigate-first \
    skills/lean-build \
    skills/migration \
    skills/safe-refactor \
    skills/surgical-patch \
    skills/verify-and-stop
```

Saída esperada: sete linhas `Installed <nome> to /home/carlosbionde/.codex/skills/<nome>`.
Se aparecer `Destination already exists`, não apague nada automaticamente: compare a instalação
existente com o commit fixado e decida a atualização em uma issue própria.

### 2. Instalar as 24 skills de Matt Pocock

```bash
python3 ~/.codex/skills/.system/skill-installer/scripts/install-skill-from-github.py \
  --repo mattpocock/skills \
  --ref 84fdeffd12f2ee307994d1eb6feb48173b6e0502 \
  --path \
    skills/engineering/ask-matt \
    skills/engineering/codebase-design \
    skills/engineering/diagnosing-bugs \
    skills/engineering/domain-modeling \
    skills/engineering/grill-with-docs \
    skills/engineering/implement \
    skills/engineering/improve-codebase-architecture \
    skills/engineering/prototype \
    skills/engineering/research \
    skills/engineering/resolving-merge-conflicts \
    skills/engineering/setup-matt-pocock-skills \
    skills/engineering/tdd \
    skills/engineering/to-spec \
    skills/engineering/to-tickets \
    skills/engineering/triage \
    skills/engineering/wayfinder \
    skills/engineering/wizard \
    skills/productivity/grill-me \
    skills/productivity/grilling \
    skills/productivity/handoff \
    skills/productivity/teach \
    skills/productivity/to-questionnaire \
    skills/productivity/wait-what \
    skills/productivity/writing-for-agents
```

Saída esperada: 24 linhas `Installed`. Depois, encerre e abra uma **nova sessão do Codex**; skills
instaladas durante uma sessão não entram retroativamente no catálogo já carregado.

## Como verificar

Uma verificação mínima confere nominalmente as 31 selecionadas e a presença do arquivo
obrigatório. Ela não conta outras skills que possam ser instaladas no futuro:

```bash
skills='caveman investigate-first lean-build migration safe-refactor surgical-patch verify-and-stop ask-matt codebase-design diagnosing-bugs domain-modeling grill-with-docs implement improve-codebase-architecture prototype research resolving-merge-conflicts setup-matt-pocock-skills tdd to-spec to-tickets triage wayfinder wizard grill-me grilling handoff teach to-questionnaire wait-what writing-for-agents'
count=0
for skill in $skills; do
  test -f "$HOME/.codex/skills/$skill/SKILL.md" || {
    printf 'Ausente: %s\n' "$skill"
    exit 1
  }
  count=$((count + 1))
done
printf '%s\n' "$count"
```

Para esta seleção, a saída esperada é `31`. Essa contagem não prova a origem dos bytes. Durante a
migração, cada diretório também foi comparado com `diff -qr` contra clones nos dois SHAs fixados;
o resultado foi `31` selecionadas e `0` diferenças.

Em uma nova sessão, a prova final deve confirmar três comportamentos:

1. o agente lista `AGENTS.md` como instrução do repositório;
2. as 31 skills aparecem no catálogo;
3. a resposta permanece em português normal e detalhado sem pedido de `caveman`.

## Riscos e manutenção

Uma skill é texto executável no sentido operacional: ela pode orientar o agente a criar arquivos,
issues, branches ou secrets. Instalar de um repositório conhecido não substitui revisão. Por isso
as versões estão fixadas, a atualização é manual e as regras locais limitam seus efeitos.

Fixar commits troca conveniência por previsibilidade. Correções futuras não chegam sozinhas, mas
uma alteração externa também não muda o comportamento do agente sem aparecer em uma revisão.

## Quão comum isso é no mercado

Arquivos versionados de instrução para agentes ainda são uma prática nova, mas seguem princípios
antigos e comuns de engenharia: configuração como código, revisão por pull request, menor
privilégio e documentação próxima ao sistema que ela descreve. Fixar uma dependência por versão
ou hash também é prática comum em builds reprodutíveis e cadeias de suprimentos de software.

O mecanismo específico `AGENTS.md` é do ecossistema de agentes; o princípio é o mesmo de um
`CONTRIBUTING.md`: qualquer colaborador começa com as mesmas regras, e mudanças nelas deixam
histórico revisável.
