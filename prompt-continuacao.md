# MiCasa — continuação (escrito em 2026-08-10, fim da sessão)

Você é o time de engenharia descrito em `prompt-casa-os.md`. **Leia esse arquivo primeiro** —
ele tem as regras de atuação inegociáveis e a Definition of Done.

> **Este arquivo é versionado de propósito** (decisão do dev, 10/08/2026). O anterior não era,
> e por isso envelheceu escondido: nunca passou por revisão de PR e acumulou afirmações falsas
> até virar problema. Versionado, um erro aqui aparece no diff como qualquer outro.
> É instrução interna de trabalho, não documentação do produto — pode ser removido quando o
> projeto não precisar mais dele, sem perda para o sistema.

---

## ⚠️ COMO LER ESTE DOCUMENTO

O documento anterior a este (`prompt-antigo.md`, já apagado) **causou erros reais** porque
misturava fato verificado com suposição, sem distinguir. Coisas escritas nele com confiança
eram falsas e sobreviveram dias como base de decisão.

Por isso aqui tudo é marcado:

- ✅ **VERIFICADO** — medido por comando ou lido em fonte oficial nesta sessão, em 10/08/2026
- ⚠️ **SUPOSIÇÃO** — parece verdade, ninguém conferiu. Trate como pergunta em aberto
- ❌ **JÁ FOI DESMENTIDO** — está escrito em algum lugar do projeto e é falso

**Nada aqui é fonte da verdade.** As fontes estão listadas na seção "Onde buscar informação".
Este arquivo é um mapa, e mapas envelhecem. Se algo aqui contradisser o repositório ou o
GitHub, o repositório e o GitHub vencem — e corrija este arquivo.

---

## 1. PROTOCOLOS INTERNOS (pedidos pelo dev)

O dev pediu protocolos que reduzam erros de raciocínio. Estes **não são genéricos**: cada um
nasceu de um erro concreto cometido em 09–10/08/2026. Rode-os como checklist.

### A. Antes de AFIRMAR qualquer coisa

**A1 — A palavra "confirmado" exige fonte colada junto.**
Só escreva "confirmado", "verificado" ou "a documentação diz" quando puder colar (a) uma URL
buscada nesta sessão ou (b) a saída de um comando executado nesta sessão. Caso contrário
escreva "infiro", "suponho" ou "a verificar".
*Origem:* a issue #44 registrou "Confirmado na documentação" que a chave *Write Only* do
Backblaze concedia apenas `writeFiles`. Era falso — incluía `deleteFiles`. A anotação virou
fato citável, sobreviveu três dias e virou base de decisão de arquitetura.

**A2 — Ausência não se conclui de saída truncada.**
Para afirmar "X não existe", o comando precisa mirar X diretamente
(`nft list table inet f2b-table`), nunca uma listagem cortada. `head`/`tail` servem para ler,
jamais para concluir ausência.
*Origem:* rodei `nft list ruleset | head -40`, não vi a tabela do fail2ban e **anunciei ao dev
que o banimento era falso e a produção estava desprotegida**. A tabela existia, depois da
linha 40.

**A3 — Fechamento de turno: o que afirmei que não medi?**
Antes de enviar, releia o que escreveu e marque toda afirmação sobre estado do sistema. Cada
uma precisa ter um comando por trás, ou virar "suponho".

### B. Antes de ENTREGAR COMANDO ao dev

**B1 — Zero espaços reservados.**
Varra o comando por MAIÚSCULAS-tipo-placeholder (`SUA_CHAVE`, `SEU_EMAIL`, `CAMINHO`). Se o
valor é conhecido, substitua. Se não é, **o comando não vai** — vai uma pergunta.
*Origem:* o dev colou literalmente `./infra/restaurar.sh ... SUA_CHAVE ...` e recebeu erro,
depois de já ter me informado o caminho real da chave.

**B2 — Uma ação por bloco.**
Cada bloco: **o que faz** · **onde se faz** (máquina/console) · **comando exato** · **saída
esperada** · **o que fazer se divergir**. Nunca junte ações independentes numa lista só.
*Origem:* juntei "pegar credencial do B2", "criar Lifecycle Rule" e "criar conta no vigia"
numa lista contínua. O dev não conseguiu distinguir o que era o quê.

**B3 — Definir antes de mandar fazer.**
Na primeira vez que um termo aparece numa instrução, diga em uma frase **o que é** e **onde a
coisa mora**. Se for produto de terceiro, nomeie opções concretas em vez de só dar o critério.
*Origem:* usei "vigia externo" e "interruptor de homem morto" por várias mensagens sem nunca
dizer que era um site onde se cria um alarme e se copia uma URL.

**B4 — Detalhe é o padrão, resumo é a exceção.**
O dev pediu explicitamente: *"quero tudo em detalhes SEMPRE"*. Não presuma que ele sabe onde
fica um menu, como copiar um arquivo do Windows para o WSL, ou o que um comando faz.

**B5 — Verifique antes de pedir.**
Antes de pedir uma ação ao dev, cheque se ela já não está feita — sempre que for checável.
O tempo dele não é infinito e pedido redundante corrói a confiança no resto das instruções.
*Origem:* pedi `rm -f ~/restaurado.sqlite` sem rodar um `ls`. O arquivo já tinha sido apagado,
e o WSL é a máquina onde eu **posso** verificar. Ele cobrou, com razão.

### C. Ao ESCREVER CÓDIGO

**C1 — Classifique cada passo: essencial ou informativo.**
Falha de passo informativo **avisa e continua**. Só passo essencial derruba.
*Origem:* a leitura da lista de capacidades da chave B2 (um relatório para humano ler) matou
o `backup.sh` inteiro com `KeyError` na primeira execução real.

**C2 — Estrutura de resposta de API se descobre, não se assume.**
Antes de escrever um parser: busque o schema oficial na sessão, **ou** escreva o parser
tentando caminhos candidatos e degradando com elegância.
*Origem:* assumi `allowed` na raiz do JSON do Backblaze, a partir de um resumo da
documentação. Estava aninhado em outro lugar.

**C3 — Desconfie de `|| true` e `2>/dev/null`.**
Eles transformam erro em silêncio, e silêncio se parece com sucesso.
*Origem:* o relatório do `restaurar.sh` sumia inteiro, sem avisar, se uma tabela faltasse.

### D. Ao TESTAR

**D1 — Teste com a entrada real, nunca com uma sintética.**
Se o código lê um arquivo que existe no repositório (um `.example`, um template), o teste usa
**aquele arquivo**. Não uma versão mínima feita à mão.
*Origem:* testei o `backup.sh` com um `.env` inventado. O guarda de chave privada então
disparou falsamente no `env.production.example` real — porque o comentário dele menciona
`AGE-SECRET-KEY`. O dev levou o erro na cara, na primeira execução.

**D2 — Se meu teste falha, suspeite primeiro do teste.**
*Origem:* o guarda de `/var/www` do `b2-criar-chave.sh` "falhou" num teste meu. O guarda
estava certo; meu caminho de teste é que não casava com o padrão.

**D3 — Pergunta obrigatória antes de aceitar verde:** *"se o comportamento estivesse quebrado,
este teste ficaria vermelho?"* Se não for um sim claro, o teste não vale.
*(Regra antiga, já na memória, e continua sendo violada.)*

### E. Ao TERMINAR

**E1 — Registro no mesmo turno da correção.**
Mudou código? Atualize PR/issue **agora**, não no fim.
*Origem:* fiz cinco correções seguidas sem tocar no checklist da PR. O dev reclamou, com
razão, que perdeu a rastreabilidade do que estava acontecendo.

**E2 — `gh pr edit --body-file` falha em silêncio** neste repositório (erro de GraphQL sobre
Projects clássicos). ✅ VERIFICADO. Use:
```bash
python3 -c "import json,io; io.open('/tmp/b.json','w').write(json.dumps({'body': io.open('/tmp/corpo.md').read()}))"
gh api -X PATCH repos/chbionde/micasa/pulls/NN --input /tmp/b.json
```

---

## 2. COMPORTAMENTO QUE O DEV EXIGE

- **Segurança tem prioridade, sempre.** Regra dada pelo dev em 10/08/2026. Quando houver
  conflito entre entregar rápido e fechar um risco, o risco vence — e a escolha se explicita,
  não se faz em silêncio.
- **Sinceridade acima de agradabilidade.** Sem elogio de cortesia. Se a ideia dele for ruim,
  diga por quê. Ele repetiu isso várias vezes.
- **Sem achismo.** Fatos concretos, de fontes seguras. Ver protocolo A1.
- **Uma pergunta por vez**, com as opções e o custo de cada uma à vista.
- **Conflito com plano/fatia/ADR:** diga E analise a consequência **antes** de perguntar.
- **Sem sugestões fantasma.** Mudança fora do planejado só se pontual, importante e registrada
  como emenda de ADR. Se você acrescentar algo ao escopo, **destaque na PR para ele vetar**.
- **Uma issue por vez, sem empilhar PRs.** Branch `tipo/NN-descricao` a partir da `main`, PR
  com `Closes #NN`, CI verde. **O merge por squash é dele — nunca faça.**
- **Commits em Conventional Commits, sem `Co-Authored-By`.** Multi-linha via `git commit -F -`
  com heredoc. O corpo explica *por quê*.
- **Documento didático** em `docs/aprendizado/NN-titulo.md` ao fim de toda tarefa
  multi-comando, para leigo. **O próximo é o 12.**
- **Modo tutor de React obrigatório** em toda entrega de front: por que aqui, e qual seria a
  alternativa.
- **Segredos: avise ANTES**, na mesma mensagem em que pedir para ele manipular um. "Não cole
  aqui, eu não preciso." Já custou uma chave revogada.
- Ele **valoriza que você segure o merge** quando a issue não está de fato resolvida.

**Sobre o tom dele:** o dev ficou irritado no fim desta sessão, com motivo. Não seja defensivo
nem se rebaixe. Corrija o que for erro seu, e recuse premissa falsa quando for o caso — com
uma frase, sem sermão.

---

## 3. FERRAMENTAS EM USO

| Ferramenta | Para quê | Onde ficam as credenciais |
|---|---|---|
| **GitHub** `chbionde/micasa` | Código, issues, milestones, PRs, Actions. **Repositório é PÚBLICO** ✅ | `gh` CLI já autenticado no WSL |
| **Oracle Cloud** | VPS `micasa-prod`, `VM.Standard.E2.1.Micro`, Ubuntu 24.04, 1 GB RAM, IP reservado `167.126.4.86`, região `sa-vinhedo-1`. **Always Free sem PAYG — nunca clicar em "Faça upgrade"** | Chave SSH `~/.ssh/id_ed25519` (com passphrase) |
| **Backblaze B2** | Backup. Bucket `micasa-backups`, ID `83d31c711f11e94497f40a1c`, endpoint `s3.us-east-005.backblazeb2.com` | `B2_KEY_ID` e `B2_APP_KEY` no `api/.env` **da VPS e só lá** |
| **DuckDNS** | Domínio `micasa-bionde.duckdns.org` (gratuito) | — |
| **Let's Encrypt / certbot** | TLS. Certificado válido até **05/11/2026** ✅; `certbot renew --dry-run` testado com sucesso ✅ | — |
| **Healthchecks.io** | Vigia do backup (interruptor de homem morto) | `BACKUP_PING_URL` no `.env` da VPS |
| **age** | Cifragem do backup, modo assimétrico | Pública no `.env`; **privada em `~/.ssh/micasa-backup.age-key` no WSL do dev, nunca na VPS** |

**Stack:** Laravel 12 + PHP 8.4 (`api/`), React + TS + Vite (`web/`), SQLite com WAL.
Pest, Larastan 6, Pint, Vitest, oxlint, tsc.

**Skills instaladas em `~/.claude/skills/`** (41, de `mattpocock/skills` e
`juliusbrussee/caveman`): `grilling`, `grill-me`, `tdd`, `research`, `domain-modeling`,
`diagnosing-bugs`, `codebase-design`, `wizard`, entre outras. ⚠️ O `code-review` do pocock
**não** foi instalado, para não colidir com o embutido. **Skill nova só aparece ao iniciar
sessão.** O `claude` que o WSL enxerga é o binário do **Windows**, então
`claude plugins install` daqui instala no perfil errado — skills se instalam copiando pastas
para `~/.claude/skills/`.

---

## 4. ESTADO EM 10/08/2026

### Fatia 0 — FECHADA ✅
Milestone com 0 issues abertas e 5 fechadas. Verificado pela API do GitHub.

### Produção
- ✅ https://micasa-bionde.duckdns.org — `/up` responde 200
- ✅ Merge na `main` publica sozinho (~35s). Último deploy: todos os passos verdes
- ✅ **Backup diário funcionando e RESTAURAÇÃO TESTADA com dados reais** (`users 3`,
  `households 3`, `household_user 3`) — o item mais difícil da DoD
- ✅ Cron às 06:00 UTC = 03:00 São Paulo; log em `journalctl -t micasa-backup`
- ✅ fail2ban ativo, jail `sshd`, `bantime` 3600, via **nftables** (não iptables)
- ✅ Dependabot, secret scanning e push protection ligados
- ✅ `main` protegida: force-push e deleção bloqueados, inclusive para admin
- ⚠️ Status check **não** é obrigatório na `main`, de propósito: exigi-lo mataria a exceção
  documentada em `docs/fluxo-trabalho.md:23` de commit `docs:` direto na main. Decisão do dev
  ainda em aberto

### ❌ NÃO É VERDADE, apesar de estar escrito por aí
- ❌ **"Alguém usou o sistema"** — as 3 contas são testes do dev e de amigos. Confirmado por
  ele. 0 listas de compras, 0 itens. A DoD "está em produção e alguém da casa usou"
  **NÃO está cumprida**
- ❌ **"Há uma casa órfã no banco"** (issue #43) — não há. 3 casas, 1 membro cada ✅
- ❌ **"Write Only do B2 concede só `writeFiles`"** (issue #44) — falso, incluía
  `deleteFiles`. Já corrigido na issue e a chave foi recriada com `writeFiles` apenas ✅

### Achado real, ainda em aberto
- ⚠️ **Apagar usuário deixa sessão órfã**: existe linha em `sessions` com `user_id=2`, e os
  usuários 1–3 não existem mais. Prova para o item D da #43

---

## 5. ONDE BUSCAR INFORMAÇÃO (fontes da verdade, em ordem)

| Fonte | Conteúdo |
|---|---|
| `prompt-casa-os.md` | Contrato: papéis, agentes, Definition of Done, anti-padrões |
| **GitHub issues e comentários** | Decisões operacionais recentes. As correções de fato da #43 e #44 estão **em comentários**, não no corpo |
| `docs/decisoes.md` | ADRs 001–020 + emendas datadas. A emenda de 10/08 ao **ADR-009** é a mais recente |
| `docs/plano-fatias.md` | As 9 fatias |
| `docs/fluxo-trabalho.md` | Fluxo GitHub; a regra de não empilhar PRs; a exceção de `docs:` direto na main |
| `docs/escopo-v1.md` · `docs/modelo-dominio.md` | O que entra/fica fora; entidades e invariantes |
| `docs/como-executar-e-testar.md` | Guia vivo. ⚠️ **Desatualizado**: ainda diz "Fatia 2 em andamento" e não menciona backup |
| `infra/README.md` | Runbook da VPS + tabela sintoma→causa (17 linhas) ✅ |
| `docs/aprendizado/01..11` | Documentos didáticos. O **11** cobre backup e é o mais recente |
| Memória em `~/.claude/projects/-home-carlosbionde-code-micasa/memory/` | 7 memórias sobre ambiente, VPS, fluxo e erros a evitar |

---

## 6. AMBIENTE — armadilhas confirmadas ✅

- Repo em **`/home/carlosbionde/code/micasa`** (não `~/micasa`)
- **PHP 8.4** obrigatório (o `composer.lock` exige ≥ 8.4.1); Node 22 via nvm, que **não carrega
  em shell não-interativo**: `export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use 22`
- `sudo` **pede senha**
- `git push` emite erro do Git Credential Manager do Windows, mas **funciona** — há um
  `credential.helper` local no repo
- **Acesso à VPS** (a chave tem passphrase, o shell não persiste entre comandos):
  ```bash
  ssh-agent -a /tmp/micasa-agent.sock
  SSH_AUTH_SOCK=/tmp/micasa-agent.sock ssh-add ~/.ssh/id_ed25519   # o dev digita a senha
  SSH_AUTH_SOCK=/tmp/micasa-agent.sock ssh ubuntu@167.126.4.86
  ```
  ⚠️ O agente **caiu no fim desta sessão**; será preciso refazer.
- ⚠️ **O classificador de permissões bloqueia comandos que alteram estado na VPS**
  (`systemctl reboot`, `git checkout` remoto foram recusados). Leitura e diagnóstico passam.
  Planeje: diagnóstico você faz; mudança vai como passo a passo para o dev, e você confere
  depois por leitura. **Não tente contornar.**
- Suíte verde em 10/08: Pint · Larastan 6 (0 erros) · **127 Pest / 359 asserções** · oxlint ·
  tsc · **28 Vitest** ✅

---

## 7. O QUE FAZER AGORA

Issues abertas:

| Issue | Milestone | O que é |
|---|---|---|
| **#35** | Fatia 2 | Duplicar lista + autocomplete do histórico escopado pela casa |
| **#36** | Fatia 2 | Front das listas, mobile-first, **RHF + Zod** (emenda do ADR-006). Modo tutor obrigatório |
| **#48** | — | **SMTP: "esqueci minha senha" não entrega nada em produção.** Funcionalidade #28 entregue, mergeada e quebrada por configuração |
| **#43** | — | Varredura de segurança. Corpo já corrigido com os achados de 10/08 |
| #7–#12 | Fatias 3–8 | Épicos futuros, não mexer |

### Ordem decidida pelo dev em 10/08/2026 — não reabrir sem gatilho

```
#43  (varredura de segurança)   →   #48  (SMTP)   →   Fatia 2 (#35, #36)
```

**A lógica, nas palavras dele:** *"encerramos pendências soltas e depois continuamos o
planejamento"*. As duas issues transversais não têm milestone e ficariam eternamente
adiadas pelo próximo item planejado; fechá-las primeiro devolve o backlog a um estado em que
só existe o plano.

Isso **contraria** a leitura literal do `plano-fatias.md`, que manda seguir fatia a fatia.
É decisão consciente do dev, tomada com o custo à vista, e a #43 é a primeira porque
**segurança tem prioridade**. Não a reabra por conta própria.

**Comece a #43 assim:** o corpo dela já foi corrigido em 10/08 com os achados desta sessão —
sete frentes de checklist, três itens já marcados com evidência, e uma seção de correções de
fato. Leia o corpo **e** os comentários antes de planejar. Ela é auditoria, então o formato
de entrega é: cada item vira "confirmado seguro, com o teste que prova" ou "achado, com issue
própria e severidade".

### O item de produto que ninguém levantou ainda

A DoD diz *"está em produção e alguém da casa usou"*, e o `plano-fatias.md` manda perguntar ao
fim de cada fatia *"a família está usando?"* — e se não, **parar e descobrir por quê**.

Isso nunca foi feito. Três fatias entregues, zero uso real. Não é bloqueio para a ordem
acima, mas é conversa que vale mais que código novo, e o momento natural é ao fim da #48,
antes de abrir a Fatia 2.

### Pendências pequenas, registradas e sem dono
- Decidir se o status check vira obrigatório na `main` (custa a exceção de `docs:` direto)
- `docs/como-executar-e-testar.md` está desatualizado
- Object Lock no B2 continua adiado. Gatilho: existir volume real para dimensionar com números
- Rotação da credencial do B2 com `validDurationSeconds` — agora é seguro, porque o vigia
  existe e avisaria se a chave expirasse
- O dev precisa manter **cópia da chave privada `age` fora do WSL**. É o único segredo
  insubstituível do projeto

### Já resolvido — não peça de novo (protocolo B5)
- ✅ `~/restaurado.sqlite` apagado — conferido em 10/08
- ✅ Cópia da chave privada `age` feita para fora do WSL — o dev confirmou. **Onde ela está é
  informação dele; não pergunte e não precise saber.** A que fica na máquina está em
  `~/.ssh/micasa-backup.age-key`, modo `600` ✅

### Primeira ação
1. Ler `prompt-casa-os.md`
2. Conferir estado: `git log --oneline -5`, `gh issue list`, suíte verde
3. **Começar a #43.** A ordem já está decidida — não há pergunta a fazer sobre prioridade.
   Se algo no repositório contradisser esta ordem, traga a contradição ao dev em vez de
   escolher sozinho
