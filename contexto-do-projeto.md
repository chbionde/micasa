# MiCasa — contexto portátil do projeto

**Escrito em 12/08/2026.** Este arquivo existe para que **qualquer assistente de IA** — ou
qualquer pessoa nova — consiga retomar o projeto sem depender do que estava na cabeça de
ninguém.

## Por que este arquivo existe

Boa parte do que um assistente sabe sobre um projeto **não vive no repositório**: fica numa
memória privada da ferramenta, some quando você troca de assistente, e envelhece sem ninguém
notar. Este arquivo tira isso do escuro e coloca no Git, onde um erro aparece no diff.

Ele **não** substitui:

| Arquivo | Papel |
|---|---|
| `prompt-casa-os.md` | O **contrato**: papéis, agentes, Definition of Done, anti-padrões |
| `prompt-continuacao.md` | O **estado e os protocolos**: onde o trabalho parou, e os erros que não se pode repetir |
| **Este arquivo** | O **contexto portátil**: plataformas, ambiente, e o que aprendemos sobre como trabalhar aqui |

**Ordem de leitura para começar:** `prompt-casa-os.md` → este arquivo → `prompt-continuacao.md`.

⚠️ **Nada aqui é fonte da verdade sobre o código.** Se este arquivo contradisser o repositório
ou o GitHub, eles vencem — e corrija este arquivo no mesmo turno.

🔒 **Este arquivo não contém segredo nenhum, e não pode passar a conter.** Ele diz *onde* cada
credencial mora, nunca o valor dela. O repositório é **público**.

---

## 1. O que é o MiCasa

Sistema de gestão doméstica para uma família: listas de compras, tarefas, contas a pagar,
agenda, e um bot de Telegram para consultar tudo isso pelo celular.

Tem **dois objetivos igualmente importantes**, e eles às vezes conflitam:

1. **Produto** — um sistema real, usado diariamente por uma família, que precisa funcionar.
2. **Aprendizado** — o dev quer sair do projeto empregável em **React + TypeScript**.

Quando conflitarem (ex.: uma biblioteca que resolve tudo vs. escrever o componente à mão),
**explicite o conflito e pergunte**. Nunca decida sozinho por conveniência.

**Sobre o dev:** desenvolvedor PHP experiente, Laravel intermediário, **React iniciante**.
Tem cerca de 5 horas por semana. Não presuma que ele sabe onde fica um menu de um serviço, o
que um comando faz, ou como copiar um arquivo do Windows para o WSL.

---

## 2. Plataformas e contas

| Plataforma | Para quê | Identificadores | Onde ficam as credenciais |
|---|---|---|---|
| **GitHub** | Código, issues, PRs, Actions, milestones | Repositório `chbionde/micasa`, **PÚBLICO** | `gh` CLI autenticado como `chbionde` (`~/.config/gh/hosts.yml`) |
| **Oracle Cloud** | VPS de produção | `micasa-prod`, `VM.Standard.E2.1.Micro`, Ubuntu 24.04, **1 GB RAM**, 1/8 OCPU, IP fixo `167.126.4.86`, região `sa-vinhedo-1` | Chave SSH pessoal `~/.ssh/id_ed25519` (comentário `micasa-oracle`, **com passphrase**) |
| **DuckDNS** | Domínio gratuito | `micasa-bionde.duckdns.org` | — |
| **Let's Encrypt / certbot** | TLS | Certificado até **05/11/2026**, renovação automática testada | — |
| **Backblaze B2** | Destino do backup | Bucket `micasa-backups`, ID `83d31c711f11e94497f40a1c`, endpoint `s3.us-east-005.backblazeb2.com` | `B2_KEY_ID` e `B2_APP_KEY` no `api/.env` **da VPS, e só lá** |
| **Healthchecks.io** | Vigia do backup (avisa se o backup parar de rodar) | — | `BACKUP_PING_URL` no `.env` da VPS |

### ⚠️ Oracle Cloud — armadilha de conta

A VPS está no plano **Always Free, sem PAYG**. **Nunca clicar em "Faça upgrade"** no console:
isso converte a conta e passa a cobrar. A cota gratuita ARM foi cortada para 2 OCPU / 12 GB em
junho de 2026 (não são mais os 4/24 que a documentação antiga cita).

### Secrets do GitHub Actions

`SSH_PRIVATE_KEY` · `SSH_HOST` · `SSH_USER` · `SSH_KNOWN_HOSTS`.
O `SSH_USER` é **`micasa-deploy`** desde 12/08/2026.

---

## 3. Stack técnica

- **Back:** Laravel 12 + **PHP 8.4** (`api/`) — o `composer.lock` exige `>= 8.4.1`, o 8.3 não
  completa nem o `composer install`
- **Front:** React + TypeScript + Vite (`web/`), Tailwind, React Router, axios
- **Banco:** SQLite com WAL. Sem Redis, sem Docker
- **Qualidade:** Pest, Larastan nível 6, Pint, Vitest, oxlint, tsc
- **CI:** dois workflows (`ci-api.yml`, `ci-web.yml`) com jobs de nomes distintos (`api`, `web`),
  sem filtro de `paths:`, mais `composer audit` e `npm audit`
- **Deploy:** `deploy.yml` — merge na `main` publica sozinho

**Vetado sem discussão prévia:** Livewire (contradiz o objetivo de aprender React), biblioteca
não-oficial de WhatsApp, ORM alternativo, microserviços, Docker Compose com muitos containers.

---

## 4. Ambiente de desenvolvimento (WSL) — armadilhas confirmadas

- Repositório em **`/home/carlosbionde/code/micasa`** — **não** em `~/micasa`
- **`sudo` pede senha**, e ela não é vazia
- **Node via nvm não carrega em shell não-interativo:**
  ```bash
  export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use 22
  ```
- **`git push` imprime erro do Git Credential Manager do Windows, mas funciona** — o
  `~/.gitconfig` aponta para um caminho com espaço que quebra; há um `credential.helper` local
  no repositório que resolve
- **`pgrep -f <padrão>` casa com a própria linha de comando.** Um `pkill -f` derrubou o shell
  da sessão. Use `pgrep -a <programa>`
- Ferramentas locais úteis para **teste de verdade**: `nginx`, `visudo`, `age`, `sqlite3`,
  `man`. **Docker não é utilizável** nesta máquina

### Comandos que falham neste repositório

| Comando | Problema | Use |
|---|---|---|
| `gh pr edit --body-file` | Erro de GraphQL sobre Projects clássicos | `gh api -X PATCH repos/chbionde/micasa/pulls/NN --input corpo.json` |
| `gh issue view --comments` | Mesmo erro | `gh api repos/chbionde/micasa/issues/NN --jq '.body'` |
| `gh pr checks NN --json` | Flag não existe nesta versão do `gh` | `gh api repos/chbionde/micasa/commits/SHA/check-runs` |

Para montar o JSON de corpo de PR:
```bash
python3 -c "import json,io; io.open('/tmp/b.json','w').write(json.dumps({'body': io.open('/tmp/corpo.md').read()}))"
```

---

## 5. Produção

**https://micasa-bionde.duckdns.org** · app em `/var/www/micasa` · saúde em `/up`

### Acesso SSH a partir do WSL

A chave tem passphrase e o shell não persiste entre comandos, então use um agente com socket
fixo:
```bash
ssh-agent -a /tmp/micasa-agent.sock
SSH_AUTH_SOCK=/tmp/micasa-agent.sock ssh-add ~/.ssh/id_ed25519   # o dev digita a senha
SSH_AUTH_SOCK=/tmp/micasa-agent.sock ssh ubuntu@167.126.4.86
```

### 🚧 Limite do ambiente do agente

**O classificador de permissões do Claude Code bloqueia comandos que alteram estado na VPS.**
Leitura e diagnóstico passam (inclusive `sudo iptables -S`, `certbot renew --dry-run`, `sqlite3`
no banco). Escrita não.

**Planeje em torno disso:** o diagnóstico é seu; a mudança vai como **passo a passo para o dev**,
e você confere depois por leitura. Não tente contornar.

### 🪤 A armadilha que mais custou tempo

**Mudança em `infra/nginx/` NÃO sai no deploy.** O deploy builda o front, faz `rsync` do `dist`
e roda o `deploy.sh` — não encosta no nginx. Enquanto ninguém aplicar, o repositório diz uma
coisa e a produção faz outra.

Para aplicar, na VPS:
```bash
cd /var/www/micasa && git pull --ff-only && ./infra/aplicar-nginx.sh
```

O script também **restaura o TLS**: o template só declara `listen 80`, e as linhas de HTTPS são
escritas pelo `certbot`. Regenerar o arquivo apaga o HTTPS, e sem `certbot install` o site volta
em HTTP puro — com `SESSION_SECURE_COOKIE=true`, ninguém consegue entrar.

### Outras armadilhas de produção, já embutidas nos scripts

- `add_header` do nginx **não é aditivo**: uma `location` com `add_header` próprio descarta
  todos os herdados do `server`. Por isso os cabeçalhos de segurança são um `include`
- O usuário de deploy **precisa** estar no grupo `www-data`, senão o `migrate` falha com
  "attempt to write a readonly database" (o `-wal` é criado pelo PHP-FPM). **Grupo novo só vale
  em sessão SSH nova**
- `MAIL_MAILER=log` grava em nível DEBUG, e o `.env` de produção usa `LOG_LEVEL=warning` — por
  isso o link de "esqueci minha senha" não aparece em lugar nenhum (é a issue #48)

### Scripts de infraestrutura

| Script | Quando roda | Observação |
|---|---|---|
| `provision.sh` | Uma vez, em VPS nova | **Exige `DOMINIO=`**: `sudo DOMINIO=... ./infra/provision.sh` |
| `deploy.sh` | A cada publicação, pela Action | Recusa rodar fora da `main` |
| `micasa-pos-deploy.template` | — | Os 3 passos que exigem root; instalado em `/usr/local/sbin/` |
| `criar-conta-deploy.sh` | Uma vez por VPS | Cria a conta de deploy e a regra de sudo restrita |
| `aplicar-nginx.sh` | Sempre que `infra/nginx/` mudar | Restaura o TLS e repõe a config anterior se `nginx -t` falhar |
| `limpar-banco.sh` | À mão, raramente | **Destrutivo**, com backup cifrado obrigatório antes |
| `backup.sh` · `restaurar.sh` · `b2-criar-chave.sh` | Backup diário e restauração | `--testar` e `--local` para exercitar sem gastar upload |

---

## 6. Fluxo de trabalho — regras do dev

- **Uma issue por vez, sem empilhar PRs.** Branch `tipo/NN-descricao` a partir da `main`, PR com
  `Closes #NN`, CI verde. **O merge é dele — nunca faça.** Correções descobertas durante a issue
  vão na **mesma PR**
- **PR mergeada = branch encerrada.** Commit novo exige branch nova e PR nova
- **Commits em Conventional Commits, sem `Co-Authored-By`.** Multi-linha via `git commit -F -`
  com heredoc. O corpo explica *por quê*, não *o quê*
- **Documento didático** em `docs/aprendizado/NN-titulo.md` ao fim de toda tarefa multi-comando,
  escrito **para leigo**: o que foi feito, como replicar, para que serve, e quão comum é no
  mercado. **O próximo é o 13**
- **Modo tutor de React obrigatório** em toda entrega de front: por que essa escolha aqui, e qual
  seria a alternativa
- Ele **valoriza que você segure o merge** quando a issue não está de fato resolvida

### O que ele exige do seu comportamento

- **Segurança em primeiro lugar, sempre.** E limitação de hardware **não** é argumento para
  relaxá-la: *"a VPS podemos mudar no futuro"*
- **Sinceridade acima de agradabilidade.** Ele pediu com todas as letras: *"não quero que você
  concorde com algo só porque falei, preciso que você avalie e discorde de mim quando
  necessário"*. Concordar por conveniência é falha de entrega
- **Uma pergunta por vez**, com as opções e o custo de cada uma à vista
- **Detalhe é o padrão:** *"quero tudo em detalhes SEMPRE"*
- **Sem sugestões fantasma.** Se acrescentar algo ao escopo, **destaque na PR para ele vetar**
- **Conflito com plano/fatia/ADR:** diga E analise a consequência **antes** de perguntar

### Formato de instrução que ele aceita

Cada passo precisa de: **o que faz** · **onde se faz** (qual máquina) · **comando exato** ·
**saída esperada** · **o que fazer se divergir**.

O modelo vivo está em `infra/README.md`, seção *"Migrando uma VPS que já existe"*. Ele rejeitou
explicitamente o formato anterior: *"preciso de passo-a-passo e não uma lista de comando
aleatórios sem justificativa e explicação"*.

---

## 7. O que aprendemos trabalhando aqui

Esta seção é a **memória do projeto**, transcrita da memória privada do assistente para dentro
do Git. Cada item nasceu de um erro concreto, com data.

### Verificar antes de afirmar

Quando um fato é externo e verificável, **verifique** — não responda de memória.

*Custou duas vezes:* afirmei de memória que a cota ARM gratuita da Oracle era 4 OCPU/24 GB (foi
cortada para 2/12); e validei escrita no banco com `php artisan migrate` num banco já migrado —
respondeu "Nothing to migrate", que **não escreve nada**. Registrei como "funciona" algo que não
tinha exercitado, e o defeito apareceu só na produção.

**Antes de aceitar um teste verde, pergunte:** *"se o comportamento estivesse quebrado, este
teste ficaria vermelho?"* Se não for um sim claro, o teste não vale. Melhor ainda: **meça** —
reverta o código e confira que ele fica vermelho.

### Segredos: classifique antes de instruir

- **Recriável** (credencial do B2, chave SSH de deploy): se perder, gera outra no console.
  **Vive no servidor e só lá.** Não peça cópia externa — aumenta a exposição sem ganho
- **Insubstituível** (a chave `age` que decifra o backup): se perder, o dado morre. **Exige
  cópia fora da máquina que ela protege**

*Origem:* tratei os dois como iguais e mandei o dev guardar as credenciais do B2 fora do
servidor. Pior: a instrução o levou a **colar a credencial real no chat**, e ele teve que
revogar e recriar a chave.

**Avise ANTES, na mesma mensagem:** *"não cole aqui, eu não preciso"*. Depois do vazamento é
tarde. E prefira desenhos que **eliminem** o segredo a custodiá-lo — o `age` em modo assimétrico
deixa só a chave pública na VPS, então não há material de decifragem a proteger no servidor.

### Trade-off se declara, nunca se embute

Quando uma decisão troca segurança por desempenho, simplicidade ou UX, **nomeie a troca**.
Desconfie da própria justificativa quando ela for curta e elegante demais.

*Origem:* reduzi o timeout de um verificador de senha vazada de 30 s para 3 s justificando com
*"se vai passar, que passe rápido"*. A frase só valia para as requisições que fracassariam de
qualquer jeito — para as lentas, o timeout curto convertia verificação que **funcionaria** em
aprovação silenciosa, porque aquele verificador **falha aberto**.

**Prefira consertar o modo de falha a ajustar parâmetros.** Falha fechada desfaz o impasse: com
ela, timeout e limites viram questão de usabilidade, não de segurança.

### Antes de confiar numa verificação, descubra o que ela faz **quando falha**

"Falha aberto" e "falha fechado" são decisões de projeto, e quase nunca estão documentadas na
primeira página.

### Comando entregue ao dev se confere

Rode-o, ou pelo menos leia o cabeçalho de uso do script, antes de mandar. E quando corrigir um
comando errado, **procure a mesma frase em todo o repositório** — ela costuma estar em mais de
um lugar (aconteceu: o README e o aviso dentro de um script).

Instrução que **nomeia algo que você nunca viu** é adivinhação. Se não pode ver o arquivo, peça
o conteúdo antes — ou dê um comando que decida sozinho, sem o humano ter que escolher.

### CI verde não prova o que o CI não roda

Os jobs cobrem PHP e front. **Nenhum** exercita shell script, configuração de nginx, sudoers ou
o `deploy.sh`. Para essas, o teste é outro — e existe:

```bash
bash -n script.sh                       # sintaxe
visudo -c -f regra                      # regra de sudo, ANTES de instalar
nginx -t -p <prefixo> -c <conf>         # configuração de nginx num prefixo de teste
```

E dá para ir além: subir um nginx de verdade numa porta alta e **medir os cabeçalhos**, ou rodar
um script com binários falsos no `PATH` para exercitar os caminhos de falha.

### Quando o teste falha, suspeite primeiro do teste

Aconteceu várias vezes, sempre corretamente: `nginx -t` faltando um arquivo do sistema no
prefixo de teste, `install` recusando um usuário inexistente, `assertOk()` esperando 200 onde a
API devolve 201.

### Cuidados de shell que já quebraram coisa

- **`${VAR}` vazio seguido de flag vira comando.** `${SUDO} -n -l -U` com `SUDO=""` expande para
  ` -n -l -U` e o shell tenta executar `-n`. Separe *prefixo de elevação* (pode ser vazio) do
  *binário* (nunca vazio)
- **Conferência não pode exigir credencial que a conta não tem.** `sudo -u conta sudo -l` numa
  conta sem senha falha sempre, mesmo com tudo certo. O certo é `sudo -l -U conta comando`, a
  partir do root
- **Quando a conferência falhar, imprima a evidência antes de abortar.** "Confira o arquivo X"
  manda o dev a um beco: ele em geral não lê X sem root, e você já está com root na mão
- **Nunca termine uma frase com um caminho seguido de ponto final** — o dev colou
  `/etc/sudoers.d/micasa-deploy.` com o ponto junto
- **`git add -A` varre o que não é seu.** Confira `git status --short` antes de commitar

### O sistema ainda não tem usuário real

Está no ar desde 07/08/2026, mas **nunca teve uso real**. Em 12/08 o dev limpou o banco, então
hoje há **zero contas**.

Isso importa por dois motivos. Primeiro, não descreva o sistema como "produção com dados reais".
Segundo, o item mais difícil da Definition of Done — *"está em produção e alguém da casa usou"* —
**não está cumprido**, e o `plano-fatias.md` manda perguntar ao fim de cada fatia *"a família
está usando?"*, parando para investigar se não. Isso nunca foi feito.

---

## 8. Configuração do assistente

### Memória

Se o seu assistente tiver memória persistente, os fatos da seção 7 são o ponto de partida.
**Mantenha-a alinhada com este arquivo** — memória desatualizada é o modo de falha que este
projeto já pagou caro: um documento anterior envelheceu escondido, nunca passou por revisão, e
acumulou afirmações falsas que viraram base de decisão por dias.

Se **não** tiver memória, este arquivo mais o `prompt-continuacao.md` cobrem o essencial.

### Skills (específico do Claude Code)

41 skills instaladas em `~/.claude/skills/`, vindas de `mattpocock/skills` e
`juliusbrussee/caveman`. As mais usadas neste projeto: `grilling`, `tdd`, `research`,
`domain-modeling`, `diagnosing-bugs`, `codebase-design`, `wizard`.

Três coisas que se aprendeu do jeito difícil:

1. **Skill nova só é descoberta ao iniciar sessão.** Instalar no meio de uma sessão não a torna
   chamável
2. **O binário `claude` que o WSL enxerga é o do Windows**
   (`/mnt/c/Users/carlo/AppData/Roaming/npm/claude`). Por isso `claude plugins install` rodado do
   WSL instala no perfil errado — as skills daqui foram instaladas **copiando pastas** para
   `~/.claude/skills/`
3. O `code-review` do pocock foi deliberadamente **não** instalado: colide com o embutido

**Se você usa outra ferramenta de IA:** nada aqui é obrigatório. O que importa é o
comportamento das seções 6 e 7, não o mecanismo.

### Configuração do projeto

- `.claude/settings.local.json` — permissões locais, **não versionado**
- Não existe `CLAUDE.md` neste repositório. O papel dele é feito por `prompt-casa-os.md`
  (contrato) e `prompt-continuacao.md` (estado e protocolos)

---

## 9. Onde está a verdade

| Fonte | Conteúdo |
|---|---|
| `prompt-casa-os.md` | Contrato: papéis, agentes, DoD, anti-padrões |
| `prompt-continuacao.md` | Estado da última sessão e os protocolos anti-erro |
| **GitHub issues** | Decisões operacionais recentes |
| `docs/decisoes.md` | ADRs 001–020 com emendas datadas |
| `docs/escopo-v1.md` · `docs/modelo-dominio.md` | O que entra e o que fica de fora; entidades e invariantes |
| `docs/plano-fatias.md` | As fatias verticais e a ordem |
| `docs/fluxo-trabalho.md` | Fluxo GitHub e a exceção de commit `docs:` direto na `main` |
| `docs/seguranca.md` | Varredura de segurança: 13 achados, o que foi medido e como, e o que **não** foi coberto |
| `docs/como-executar-e-testar.md` | ⚠️ **Desatualizado** — diz "Fatia 2 em andamento", não menciona backup nem segurança |
| `infra/README.md` | Runbook da VPS, tabela sintoma→causa, e o modelo de passo a passo |
| `docs/aprendizado/01..12` | Documentos didáticos. O **12** cobre a varredura de segurança |

---

## 10. Estado em 12/08/2026 e o que vem a seguir

**Suíte verde:** Pint · Larastan 6 (0 erros) · **162 Pest / 481 asserções** · oxlint · tsc ·
**32 Vitest**

**Fatias 0 e 1 entregues.** Fatia 2 (listas de compras) tem o back pronto e o front pendente.

### Issues abertas

| Issue | O que é |
|---|---|
| **#53** | CSP — no ar em modo relato; falta o dev conferir o console numa janela anônima |
| **#54** | HSTS — não começada; fecha em `max-age=2592000` |
| **#55** | Chave de deploy — passos 1–6 feitos; falta o passo 7 do `infra/README.md` |
| **#48** | SMTP: "esqueci minha senha" não entrega nada em produção |
| **#35 · #36** | Fatia 2 — listas de compras. A #36 é front, com modo tutor obrigatório |
| #7–#12 | Épicos das fatias 3–8; não mexer |

### Ordem decidida pelo dev

```
#53, #54, #55   →   #48 (SMTP)   →   Fatia 2 (#35, #36)
```

### 🚫 Restrição ativa

O dev proibiu, em 12/08/2026, **criar qualquer issue nova de infra ou segurança** até fechar o
que está aberto. Vale até ele liberar. PR não é issue — PRs continuam normais.
