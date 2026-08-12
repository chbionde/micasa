# MiCasa — continuação (escrito em 2026-08-12, fim da sessão)

Você é o time de engenharia descrito em `prompt-casa-os.md`. **Leia esse arquivo primeiro** —
ele tem as regras de atuação inegociáveis e a Definition of Done.

> **Este arquivo é versionado de propósito** (decisão do dev, 10/08/2026). O anterior a ele não
> era, e por isso envelheceu escondido: nunca passou por revisão de PR e acumulou afirmações
> falsas até virar problema. Versionado, um erro aqui aparece no diff como qualquer outro.
> É instrução interna de trabalho, não documentação do produto — pode ser removido quando o
> projeto não precisar mais dele, sem perda para o sistema.

---

## ⚠️ COMO LER ESTE DOCUMENTO

Tudo aqui é marcado:

- ✅ **VERIFICADO** — medido por comando ou lido em fonte oficial em 12/08/2026
- ⚠️ **SUPOSIÇÃO** — parece verdade, ninguém conferiu. Trate como pergunta em aberto
- ❌ **JÁ FOI DESMENTIDO** — está escrito em algum lugar do projeto e é falso

**Nada aqui é fonte da verdade.** As fontes estão na seção "Onde buscar informação". Este
arquivo é um mapa, e mapas envelhecem. Se algo aqui contradisser o repositório ou o GitHub, o
repositório e o GitHub vencem — e corrija este arquivo.

---

## 1. PROTOCOLOS INTERNOS

Cada um nasceu de um erro concreto, com data. Rode-os como checklist. **A sessão de 12/08 foi
dominada por erros de infraestrutura entregues ao dev sem verificação — a seção B e a seção C
cresceram por isso, e ele terminou o dia irritado, com razão.**

### A. Antes de AFIRMAR qualquer coisa

**A1 — A palavra "confirmado" exige fonte colada junto.**
Só escreva "confirmado", "verificado" ou "a documentação diz" quando puder colar (a) uma URL
buscada nesta sessão ou (b) a saída de um comando executado nesta sessão. Caso contrário
escreva "infiro", "suponho" ou "a verificar".
*Origem (09/08):* a issue #44 registrou "Confirmado na documentação" que a chave *Write Only*
do Backblaze concedia apenas `writeFiles`. Era falso — incluía `deleteFiles`.

**A2 — Ausência não se conclui de saída truncada.**
Para afirmar "X não existe", o comando precisa mirar X diretamente
(`nft list table inet f2b-table`), nunca uma listagem cortada. `head`/`tail` servem para ler,
jamais para concluir ausência.
*Origem (10/08):* rodei `nft list ruleset | head -40`, não vi a tabela do fail2ban e anunciei
ao dev que a produção estava desprotegida. A tabela existia, depois da linha 40.

**A3 — Fechamento de turno: o que afirmei que não medi?**
Antes de enviar, releia o que escreveu e marque toda afirmação sobre estado do sistema. Cada
uma precisa de um comando por trás, ou vira "suponho".

**A4 — Quando o dev trouxer contraprova, MEÇA antes de se retratar.** ⭐ novo em 12/08
Retratação apressada é tão errada quanto teimosia, e custa a confiança do mesmo jeito.
*Origem:* o console do dev mostrou violação de CSP carregando fonte do `fonts.gstatic.com`. Eu
abri a resposta dizendo "minha varredura estava errada". Medi depois: o HTML e o CSS **de
produção** tinham zero referência a fonte externa, e o CSS era byte a byte idêntico ao meu
build. A varredura estava certa — era extensão do navegador dele. Eu quase afrouxei a política
de segurança para acomodar um falso positivo.

**A5 — Trade-off se declara, nunca se embute.** ⭐ novo em 12/08
Quando uma decisão troca segurança por desempenho, simplicidade ou UX, **nomeie a troca**.
Desconfie da própria justificativa quando ela for curta e elegante demais.
*Origem:* reduzi o timeout do verificador de senha vazada de 30 s para 3 s e justifiquei com
*"se vai passar, que passe rápido"*. A frase só valia para as requisições que fracassariam de
qualquer jeito — para as lentas, o timeout curto convertia verificação que **funcionaria** em
aprovação silenciosa, porque o verificador falha aberto. O dev não engoliu e mandou revisar. O
conserto certo era o **modo de falha**, não o tempo. Ver `docs/aprendizado/12`, seção 4.

### B. Antes de ENTREGAR COMANDO ao dev

**B1 — Zero espaços reservados.**
Varra por MAIÚSCULAS-tipo-placeholder (`SUA_CHAVE`, `CAMINHO`). Se o valor é conhecido,
substitua. Se não é, **o comando não vai** — vai uma pergunta.

**B2 — Uma ação por bloco.**
Cada bloco: **o que faz** · **onde se faz** (máquina/console) · **comando exato** · **saída
esperada** · **o que fazer se divergir**. Nunca junte ações independentes numa lista só.
*Reincidência em 12/08:* o runbook da #55 saiu como lista de comandos sem explicação. O dev
travou no passo 1 e cobrou: *"preciso de passo-a-passo e não uma lista de comando aleatórios
sem justificativa e explicação"*. O formato correto está hoje no `infra/README.md`, seção
"Migrando uma VPS que já existe" — **use-o como modelo**.

**B3 — Definir antes de mandar fazer.**
Na primeira vez que um termo aparece, diga em uma frase **o que é** e **onde a coisa mora**.

**B4 — Detalhe é o padrão, resumo é a exceção.**
O dev pediu: *"quero tudo em detalhes SEMPRE"*.

**B5 — Verifique antes de pedir.**
Antes de pedir uma ação, cheque se ela já não está feita — sempre que for checável.

**B6 — Rode o comando, ou leia o cabeçalho de uso dele, antes de mandar.** ⭐ novo em 12/08
*Origem:* mandei `sudo ./infra/provision.sh`. O script **exige `DOMINIO=`** e diz isso na
própria linha 11. O dev bateu no erro três vezes e desistiu. Eu não tinha executado nem lido o
arquivo que estava mandando ele rodar.

**B7 — Instrução que nomeia algo que você nunca viu é adivinhação.** ⭐ novo em 12/08
*Origem:* escrevi "apague a linha que termina com o comentário `micasa-deploy` antigo" sobre um
`authorized_keys` que eu nunca tinha lido. O dev abriu e achou dois comentários que eu não
conhecia, não soube qual era qual, e parou. Se você não pode ver o arquivo, **peça o conteúdo
antes de dar a instrução** — ou dê um comando que decida sozinho, sem o humano ter que
escolher.

**B8 — Comando errado se corrige em TODOS os lugares onde ele aparece.** ⭐ novo em 12/08
*Origem:* corrigi a instrução do `provision.sh` no README e esqueci que a **mesma frase** saía
como aviso dentro do `deploy.sh`. O dev recebeu a instrução errada de novo, agora pela boca do
script. Depois de corrigir um comando, faça `grep -rn` no repositório atrás dele.

### C. Ao ESCREVER CÓDIGO

**C1 — Classifique cada passo: essencial ou informativo.**
Falha de passo informativo **avisa e continua**. Só passo essencial derruba.

**C2 — Estrutura de resposta de API se descobre, não se assume.**

**C3 — Desconfie de `|| true` e `2>/dev/null`.**
Eles transformam erro em silêncio, e silêncio se parece com sucesso.

**C4 — Em shell, `${VAR}` vazio seguido de flag vira comando.** ⭐ novo em 12/08
*Origem:* `${SUDO} -n -l -U conta` com `SUDO=""` (o caso de `sudo ./script`, onde o EUID é 0)
expande para ` -n -l -U conta`, e o shell tenta executar `-n`. Saída 127.
**Separe prefixo de elevação (pode ser vazio) do binário (nunca vazio).** Lint barato:
```bash
grep -rn '\${SUDO} -' infra/
```

**C5 — Conferência não pode exigir credencial que a conta não tem.** ⭐ novo em 12/08
*Origem:* conferi a regra de sudo com `sudo -u micasa-deploy sudo -l`. O `sudo` de dentro roda
**como** aquela conta, e `sudo -l` exige que quem invoca se autentique — a conta não tem senha,
por projeto. Falhava sempre, mesmo com tudo certo, e abortava o script **depois** de ter
instalado tudo. O certo é perguntar a partir do root: `sudo -l -U conta comando`.

**C6 — Quando a conferência falhar, imprima a EVIDÊNCIA antes de abortar.** ⭐ novo em 12/08
Mensagem do tipo "confira o arquivo X" manda o dev a um beco: em geral ele não consegue ler X
sem root, e você já está com root na mão. Imprima o conteúdo, as permissões e o que a
ferramenta enxerga. *Bônus:* nunca termine uma frase com um caminho seguido de ponto final —
o dev colou `/etc/sudoers.d/micasa-deploy.` com o ponto junto.

### D. Ao TESTAR

**D1 — Teste com a entrada real, nunca com uma sintética.**

**D2 — Se meu teste falha, suspeite primeiro do teste.**
*Reincidiu 3× em 12/08, sempre corretamente:* `nginx -t` faltando `fastcgi.conf` no prefixo de
teste, `install` recusando `-o micasa-deploy` inexistente, e `assertOk()` esperando 200 onde a
API devolve 201.

**D3 — Pergunta obrigatória antes de aceitar verde:** *"se o comportamento estivesse quebrado,
este teste ficaria vermelho?"* Se não for um sim claro, o teste não vale.
**Melhor ainda: MEÇA.** Reverta o código e rode:
```bash
git checkout HEAD~1 -- app/ routes/ config/ && php artisan test tests/…
git checkout HEAD -- app/ routes/ config/
```
*Funcionou em 12/08:* 13 dos 20 testes novos ficaram vermelhos sem a correção, e os 7 verdes
eram controles de propósito.

**D4 — CI verde não prova nada sobre o que o CI não roda.** ⭐ novo em 12/08
Os jobs são Pint, Larastan, Pest, oxlint, tsc, Vitest e os dois `audit`. **Nenhum** exercita
shell script, configuração de nginx, sudoers ou o `deploy.sh`. Para essas, o teste é outro:
`bash -n`, `visudo -c -f`, `nginx -t` num prefixo de teste, harness de binários falsos.
*Funcionou em 12/08:* subi um nginx de verdade numa porta alta e medi os cabeçalhos em cinco
tipos de resposta; e rodei o `criar-conta-deploy.sh` com dublês em quatro cenários.

### E. Ao TERMINAR

**E1 — Registro no mesmo turno da correção.** Mudou código? Atualize PR/issue **agora**.

**E2 — `gh pr edit --body-file` falha em silêncio** neste repositório (erro de GraphQL sobre
Projects clássicos). ✅ VERIFICADO. `gh issue view --comments` falha pelo mesmo motivo. Use:
```bash
python3 -c "import json,io; io.open('/tmp/b.json','w').write(json.dumps({'body': io.open('/tmp/corpo.md').read()}))"
gh api -X PATCH repos/chbionde/micasa/pulls/NN --input /tmp/b.json
gh api repos/chbionde/micasa/issues/NN --jq '.body'          # ler issue
gh api repos/chbionde/micasa/issues/NN/comments --jq '.[].body'
```
⚠️ `gh pr checks NN --json` **não existe** nesta versão do `gh`. Para status de CI use
`gh api repos/chbionde/micasa/commits/SHA/check-runs`.

**E3 — PR mergeada = branch encerrada.** ⭐ novo em 12/08
Commit novo exige **branch nova e PR nova**.
*Origem:* empurrei um commit para a branch da PR #59 depois de ela já ter sido mergeada. O
commit ficou órfão, sem PR, e o dev teve que mergear na `main` à mão. Ele cobrou.

**E4 — `git add -A` varre o que não é seu.** ⭐ novo em 12/08
*Origem:* o dev colocou uma pasta `publicar/` na raiz com logs do Actions **para eu ler**. O
`git add -A` a commitou junto. Antes de commitar: `git status --short`, e prefira
`git add <caminhos>`.

---

## 2. COMPORTAMENTO QUE O DEV EXIGE

- **Segurança tem prioridade, sempre.** Quando houver conflito entre entregar rápido e fechar
  um risco, o risco vence — e a escolha se explicita. Ele reafirmou em 12/08 que limitação de
  hardware **não** é argumento para relaxar segurança: *"a VPS podemos mudar no futuro"*.
- **Sinceridade acima de agradabilidade.** Regra reforçada em 12/08, com todas as letras:
  *"não quero que você concorde com algo só porque falei, preciso que você avalie e discorde de
  mim quando necessário"*. Concordar por conveniência é falha de entrega.
- **Sem achismo.** Ver A1.
- **Uma pergunta por vez**, com as opções e o custo de cada uma à vista.
- **Sem sugestões fantasma.** Se acrescentar algo ao escopo, **destaque na PR para ele vetar**.
- **Uma issue por vez, sem empilhar PRs.** Branch `tipo/NN-descricao` a partir da `main`, PR com
  `Closes #NN`, CI verde. **O merge é dele — nunca faça.**
- **Commits em Conventional Commits, sem `Co-Authored-By`.** Multi-linha via `git commit -F -`
  com heredoc. O corpo explica *por quê*.
- **Documento didático** em `docs/aprendizado/NN-titulo.md` ao fim de toda tarefa multi-comando.
  **O próximo é o 14.**
- **Modo tutor de React obrigatório** em toda entrega de front.
- **Segredos: avise ANTES**, na mesma mensagem em que pedir para ele manipular um.
- Ele **valoriza que você segure o merge** quando a issue não está de fato resolvida.

> ✅ **RESTRIÇÃO REVOGADA PARA O CODEX, em 12/08:** o dev esclareceu que a proibição de criar
> issues de infra ou segurança era exclusiva do Claude Code, que estava criando trabalho
> desnecessário. O Codex pode criar e modificar issues e PRs quando forem necessários, mantendo
> uma tarefa por vez e sem criar itens especulativos.

**Sobre o tom:** ele terminou 12/08 cansado de dois dias de infraestrutura com erros repetidos.
Não seja defensivo nem se rebaixe. Corrija o que for erro seu em uma ou duas frases, recuse
premissa falsa quando for o caso, e entregue verificado.

---

## 3. FERRAMENTAS EM USO

| Ferramenta | Para quê | Credenciais |
|---|---|---|
| **GitHub** `chbionde/micasa` | Código, issues, PRs, Actions. **Repositório PÚBLICO** ✅ | `gh` CLI autenticado no WSL |
| **Oracle Cloud** | VPS `micasa-prod`, `VM.Standard.E2.1.Micro`, Ubuntu 24.04, 1 GB RAM, IP `167.126.4.86`, `sa-vinhedo-1`. **Always Free — nunca clicar em "Faça upgrade"** | Chave pessoal `~/.ssh/id_ed25519`, comentário `micasa-oracle`, com passphrase |
| **Backblaze B2** | Backup. Bucket `micasa-backups`, ID `83d31c711f11e94497f40a1c` | `B2_*` no `api/.env` **da VPS e só lá** |
| **DuckDNS** | `micasa-bionde.duckdns.org` | — |
| **Let's Encrypt** | TLS até **05/11/2026** ✅ | — |
| **Healthchecks.io** | Vigia do backup | `BACKUP_PING_URL` no `.env` da VPS |
| **age** | Cifragem do backup, assimétrico | Pública no `.env`; **privada em `~/.ssh/micasa-backup.age-key` no WSL, nunca na VPS** |

**Stack:** Laravel 12 + PHP 8.4 (`api/`), React + TS + Vite (`web/`), SQLite com WAL.
Pest, Larastan 6, Pint, Vitest, oxlint, tsc.

---

## 4. ESTADO EM 12/08/2026 ✅

### Suíte verde
Pint · Larastan 6 (0 erros) · **162 Pest / 481 asserções** · oxlint · tsc · **32 Vitest** ✅
(era 127/359 e 28 no início do dia)

### Produção
- ✅ https://micasa-bionde.duckdns.org — `/up` 200
- ✅ Merge na `main` publica sozinho
- ✅ **O deploy entra como `micasa-deploy`**, conta sem senha cujo único poder de root é rodar
  `/usr/local/sbin/micasa-pos-deploy`. Medido: secret trocado 19:27:04, deploy verde 19:27:52
- ✅ CSP em produção, em **dois** cabeçalhos: um que bloqueia (`object-src`, `base-uri`,
  `frame-ancestors`, `form-action`) e um `-Report-Only` com a política estrita
- ✅ Backup diário cifrado, restauração testada
- ✅ fail2ban, Dependabot, secret scanning, `main` protegida
- ❌ **HSTS ainda ausente** — é a #54, não começada
- ⚠️ **O BANCO ESTÁ VAZIO.** O dev rodou `infra/limpar-banco.sh` em 12/08. Zero contas —
  ninguém consegue entrar até alguém se cadastrar. As senhas antigas, que escapavam da política
  nova, deixaram de existir junto

### 🪤 ARMADILHA QUE CUSTOU HORAS
**Mudança em `infra/nginx/` NÃO sai no deploy.** O deploy builda o front, faz `rsync` do `dist`
e roda o `deploy.sh` — **não encosta no nginx**. Enquanto ninguém aplicar, o repositório diz uma
coisa e a produção faz outra. Para aplicar, na VPS:
```bash
cd /var/www/micasa && git pull --ff-only && ./infra/aplicar-nginx.sh
```
O `aplicar-nginx.sh` também **restaura o TLS**: regenerar o site a partir do template apaga as
linhas que o certbot escreveu, e esquecer isso devolve o site em HTTP puro sem erro visível.

### Scripts de infra (todos com `--conferir`/`--testar` ou guardas)
| Script | Quando roda |
|---|---|
| `provision.sh` | Uma vez por VPS. **Exige `DOMINIO=`** |
| `deploy.sh` | A cada publicação, pela Action |
| `micasa-pos-deploy.template` | Os 3 passos que exigem root; instalado em `/usr/local/sbin/` |
| `criar-conta-deploy.sh` | Uma vez por VPS. Cria a conta de deploy e a regra de sudo |
| `aplicar-nginx.sh` | Sempre que `infra/nginx/` mudar |
| `limpar-banco.sh` | À mão, raramente. **Destrutivo**, com backup obrigatório antes |
| `backup.sh` · `restaurar.sh` · `b2-criar-chave.sh` | Backup |

---

## 5. ONDE BUSCAR INFORMAÇÃO (fontes da verdade, em ordem)

| Fonte | Conteúdo |
|---|---|
| `AGENTS.md` | Entrada operacional nativa do Codex e ponteiros para estas fontes |
| `prompt-casa-os.md` | Contrato: papéis, DoD, anti-padrões |
| **GitHub issues** | Decisões operacionais recentes |
| `docs/seguranca.md` | ⭐ **Novo.** Resultado da varredura #43: 13 achados, o que foi medido e como, e o que a varredura **não** cobriu. Linha de base da próxima |
| `docs/decisoes.md` | ADRs 001–020 + emendas datadas |
| `docs/plano-fatias.md` · `docs/escopo-v1.md` · `docs/modelo-dominio.md` | Plano e domínio |
| `docs/fluxo-trabalho.md` | Fluxo GitHub; exceção de `docs:` direto na main |
| `docs/como-executar-e-testar.md` | ⚠️ **Desatualizado**: diz "Fatia 2 em andamento", não menciona backup nem segurança |
| `infra/README.md` | Runbook da VPS. Contém o **modelo de passo a passo** que o dev aceita |
| `docs/aprendizado/01..13` | O **13** documenta a migração do Claude Code para o Codex |
| Memória privada do Claude | Histórico somente; não copiar, pois contém fatos ultrapassados |

---

## 6. AMBIENTE — armadilhas confirmadas ✅

- Repo em **`/home/carlosbionde/code/micasa`**
- **PHP 8.4** obrigatório; Node 22 via nvm, que **não carrega em shell não-interativo**:
  `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use 22`
- `sudo` **pede senha** no WSL
- `git push` emite erro do Git Credential Manager do Windows, mas **funciona**
- O bloqueio de comandos que alteravam estado na VPS era uma limitação específica do
  **Claude Code**. No Codex, verifique as permissões da sessão atual em vez de presumir esse
  bloqueio. Mesmo quando a escrita for tecnicamente possível, só altere produção dentro do
  escopo explicitamente autorizado; fora dele, entregue o passo a passo no formato B2.
- `pgrep -f <padrão>` **casa com a própria linha de comando** — um `pkill -f` derrubou o shell
  da sessão. Use `pgrep -a <programa>`
- Ferramentas locais disponíveis para teste real: `nginx`, `visudo`, `age`, `sqlite3`,
  `man`. **Docker não é utilizável.**

---

## 7. O QUE FAZER AGORA

### Issues abertas

| Issue | O que é | Estado real |
|---|---|---|
| **#62** | Preparar o Codex para continuar o MiCasa | **Em andamento.** Skills instaladas e documentação preparada; não iniciar outra issue antes do merge |
| **#53** | CSP ausente | Código no ar em modo relato. **Falta só o dev conferir o console numa janela anônima.** A aplicação está limpa — medido: HTML e CSS de produção sem nenhuma referência externa. A violação que ele viu era extensão do navegador dele |
| **#54** | HSTS ausente | **Não começada.** Critério de aceite já ajustado: fecha em `max-age=2592000` (30 dias), com a subida para um ano como passo posterior sem dono |
| **#55** | Chave de deploy com poder de root | **Quase fechada.** Passos 1–6 feitos e verificados; falta o **passo 7** (remover a chave de deploy antiga do `authorized_keys` do `ubuntu`), reescrito no `infra/README.md` sem depender do `nano` |
| **#48** | SMTP não entrega em produção | Não começada |
| **#35 · #36** | Fatia 2 — listas de compras | Não começadas. #36 é front: **modo tutor obrigatório** |
| #7–#12 | Épicos das fatias 3–8 | Não mexer |

### Ordem decidida pelo dev

```
#62 (onboarding do Codex)   →   #53, #54, #55   →   #48 (SMTP)   →   Fatia 2 (#35, #36)
```

Ele decidiu em 12/08 que as três sub-issues vêm **antes** da #48: *"pendências soltas primeiro
e depois o planejamento já existente, assim evitamos débito técnico futuro"*. Não reabra.

### Primeira ação

1. Ler `AGENTS.md`, `prompt-casa-os.md`, `contexto-do-projeto.md` e este arquivo
2. Conferir se a issue #62 foi fechada pelo merge e se não há outro PR ativo
3. Se a #62 ainda estiver aberta, terminar somente ela; não iniciar outra tarefa
4. Depois do merge, conferir estado: `git log --oneline -5`, `gh issue list`, suíte verde
5. **Perguntar ao dev qual das três sub-issues retomar**, porque todas dependem de ação dele:
   - #53 depende do console na janela anônima
   - #55 depende do passo 7
   - #54 é a única que dá para começar sozinho — mas exige `aplicar-nginx.sh` na VPS, que é ele

### O item de produto que ninguém levantou ainda

A DoD diz *"está em produção e alguém da casa usou"*, e o `plano-fatias.md` manda perguntar ao
fim de cada fatia *"a família está usando?"* — e se não, **parar e descobrir por quê**.

Isso nunca foi feito, e agora está pior: **o banco foi limpo, então há literalmente zero contas
no sistema.** Três fatias entregues, zero uso real. É conversa que vale mais que código novo, e
o momento natural é ao fim da #48.

### Pendências pequenas, registradas e sem dono
- `docs/como-executar-e-testar.md` desatualizado
- Status check obrigatório na `main` — decisão adiada; hoje é possível, porque os jobs têm nomes
  distintos e não há filtro de `paths:`
- Object Lock no B2 — gatilho: existir volume real
- Rotação da credencial do B2 com `validDurationSeconds`
- A promoção da CSP estrita a bloqueante é **uma linha** em
  `infra/nginx/cabecalhos-seguranca.conf`, depois que o console estiver limpo

### Já resolvido — não peça de novo (protocolo B5)
- ✅ Banco de produção limpo, com backup cifrado tirado antes
- ✅ Conta `micasa-deploy` criada, wrapper instalado, sudo restrito, chave rotacionada, secrets
  atualizados, deploy verde por ela
- ✅ Cópia da chave privada `age` fora do WSL — **onde ela está é informação dele; não pergunte**
