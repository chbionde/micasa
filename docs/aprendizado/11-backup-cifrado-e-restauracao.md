# 11 — Backup cifrado e restauração testada

> Documento didático da issue #4. Escrito para quem nunca montou um backup de verdade.
> Cobre o que foi feito, por que cada decisão é assim, como replicar em outro projeto, e
> os cinco defeitos que apareceram no caminho — três deles só quando o código encostou na
> realidade.

---

## O problema, em uma frase

O MiCasa roda numa máquina que a Oracle pode desligar sem pedir licença (ADR-003). O banco
de dados é um único arquivo de 176 KB. Se aquela máquina sumir e não houver cópia em outro
lugar, o sistema não volta — não existe "recuperar do servidor", porque o servidor é o que
sumiu.

Antes desta tarefa, o MiCasa estava em produção há três dias **sem backup nenhum**.

---

## O que foi construído

Quatro peças, todas em `infra/`:

| Arquivo | O que faz |
|---|---|
| `backup.sh` | Roda todo dia às 3h da manhã. Copia o banco, comprime, cifra e envia para a nuvem |
| `restaurar.sh` | Roda à mão, na sua máquina. Decifra um backup e **prova** que ele vira um banco usável |
| `b2-criar-chave.sh` | Cria a credencial da nuvem com a permissão mínima possível |
| `cron/micasa-backup` | A linha que faz o sistema operacional chamar o `backup.sh` sozinho |

O caminho completo de um backup:

```
banco de produção
      │  sqlite3 .backup          (cópia consistente)
      ▼
cópia temporária  ──►  verificação  ──►  gzip  ──►  age  ──►  Backblaze B2
   176 KB              íntegra?          12 KB     cifrado      nuvem
                       tem dados?
```

E o caminho de volta, que é o que realmente importa:

```
Backblaze B2  ──►  age -d  ──►  gunzip  ──►  banco  ──►  "tem 3 usuários?"
              (chave privada,
               fora do servidor)
```

---

## Decisão 1 — copiar banco de dados não é copiar arquivo

### O erro que quase todo mundo comete

O instinto é `cp banco.sqlite copia.sqlite`. Parece óbvio: é um arquivo, copie o arquivo.

**Está errado, e o pior é que funciona quase sempre.**

O SQLite do MiCasa roda em modo **WAL** (*Write-Ahead Logging*). Nesse modo, quando alguém
salva uma lista de compras, o dado **não vai direto para o `banco.sqlite`**. Ele é escrito
num arquivo vizinho, `banco.sqlite-wal`, e só mais tarde é transferido para o arquivo
principal — num momento chamado *checkpoint*.

Isso quer dizer que, em qualquer instante, uma parte dos dados confirmados está no `-wal` e
ainda não está no `.sqlite`.

Se você copiar só o `.sqlite`, sua cópia **abre normalmente, passa em todas as verificações,
e está incompleta**. Você só descobre no dia da restauração, quando falta exatamente o que
foi salvo nas últimas horas.

### A forma certa

```bash
sqlite3 banco.sqlite ".backup '/tmp/copia.sqlite'"
```

O `.backup` usa a *API de backup online* do SQLite. Ela sabe do `-wal`, sabe coordenar com
quem estiver escrevendo no mesmo instante, e produz um arquivo consistente. É o mesmo
mecanismo que ferramentas profissionais usam.

Existe uma alternativa, `VACUUM INTO '/tmp/copia.sqlite'`, que também é segura e ainda
compacta o resultado. Medimos as duas neste banco e deram o mesmo tamanho; ficamos com o
`.backup` por ser o mais convencional.

### Quão comum no mercado

**Universal.** Todo banco de dados sério tem um comando de backup que não é "copie o
arquivo": PostgreSQL tem `pg_dump`, MySQL tem `mysqldump`, MongoDB tem `mongodump`. A regra
por trás é sempre a mesma: **um banco em uso não é um arquivo parado**, e copiá-lo como se
fosse produz lixo que parece bom.

---

## Decisão 2 — comprimir antes de cifrar (nunca o contrário)

O backup sai com **176.128 bytes** e chega ao destino com **12.139 bytes** — 15 vezes menor.

A ordem não é escolha estética:

- **Comprimir depois cifrar:** funciona, 15× de economia.
- **Cifrar depois comprimir:** o arquivo cifrado é, por construção, indistinguível de ruído
  aleatório. Compressão funciona achando repetição, e ruído não tem repetição. **A economia
  é praticamente zero.**

Uma boa cifra *tem* que produzir algo incompressível — se o resultado ainda tivesse padrões
aproveitáveis, seriam padrões que um atacante também poderia aproveitar.

### Quão comum no mercado

Padrão em qualquer ferramenta que faça as duas coisas: `restic`, `borg`, `duplicity`, e o
TLS do seu navegador. Todas comprimem primeiro.

---

## Decisão 3 — a chave que cifra não é a chave que decifra

Esta é a decisão mais importante do documento inteiro, e a que mais gente erra.

### O jeito intuitivo, e por que ele é ruim

Cifragem "de senha" (simétrica) usa **a mesma chave** para fechar e abrir. Simples de
entender. Mas repare no que ela obriga: o servidor precisa da chave para cifrar o backup
todo dia — logo **a chave mora no servidor**.

Agora imagine que alguém invada esse servidor. Ele encontra:
- a chave de cifragem;
- a credencial da nuvem, no mesmo arquivo.

Com as duas, ele baixa e lê **todos os backups, inclusive os antigos**. O backup, que devia
ser a última linha de defesa, vira um arquivo histórico completo entregue de bandeja.

### O jeito certo: par de chaves

O `age` (a ferramenta usada aqui) trabalha com **duas chaves diferentes**:

| Chave | Serve para | Onde vive |
|---|---|---|
| **Pública** (`age1k804q5...`) | **só cifrar** | no servidor — e pode até estar no GitHub |
| **Privada** (`AGE-SECRET-KEY-1...`) | **só decifrar** | fora do servidor, e **só lá** |

A chave pública não é segredo. Ela está versionada no `infra/env.production.example` deste
repositório, que é público. Qualquer pessoa pode pegá-la e cifrar um arquivo para você —
e **nenhuma** delas consegue abrir um arquivo cifrado, nem quem cifrou.

**Consequência prática:** um invasor que domine a VPS do MiCasa hoje pode apagar o servidor,
mas **não consegue ler um único backup**. O material para decifrar simplesmente não está lá.

### O detalhe que quase escapa

A chave precisa ser **gerada fora do servidor**. Gerar lá e apagar depois **não é a mesma
coisa**: sobra rastro no histórico do shell, no log do sistema, e possivelmente em blocos
livres do disco. Gerada fora, ela nunca esteve lá — e "nunca esteve" é uma garantia
diferente, e muito mais forte, do que "foi apagada".

Para reforçar isso, o `backup.sh` **se recusa a rodar** se encontrar uma chave privada no
arquivo de configuração:

```bash
if grep -vE '^[[:space:]]*#' "${ENV_FILE}" | grep -q 'AGE-SECRET-KEY'; then
  erro "Há uma chave PRIVADA age no .env. A VPS só pode conter a pública."
fi
```

### A regra dos dois tipos de segredo

Esta tarefa deixou clara uma distinção que vale para a vida:

| Tipo | Exemplos aqui | Regra |
|---|---|---|
| **Recriável** | credencial do Backblaze, URL do vigia, chave SSH de deploy | Vive **no servidor e só lá**. Perdeu? Gera outra no painel. Guardar cópia fora só aumenta a chance de vazar, sem ganho nenhum |
| **Insubstituível** | a chave privada `age` | **Exige** cópia fora da máquina que ela protege. Se ela morrer junto com o servidor, os backups viram lixo cifrado para sempre |

Tratar os dois como iguais é erro dos dois lados: guardar segredo recriável em toda parte
aumenta a exposição à toa, e deixar segredo insubstituível só no servidor garante que ele
não exista exatamente quando for necessário.

### Quão comum no mercado

Cifragem assimétrica é a base do HTTPS, do SSH, da assinatura de pacotes de software e do
PGP. **Aplicá-la a backup ainda é menos comum do que deveria** — muita empresa ainda usa
senha simétrica guardada no mesmo servidor. Saber explicar por que isso é fraco é um bom
diferencial em entrevista.

---

## Decisão 4 — a credencial da nuvem só pode escrever

O princípio se chama **menor privilégio**: dê a cada peça exatamente o que ela precisa, e
nada além.

O `backup.sh` precisa **enviar arquivo**. Não precisa listar, nem baixar, nem apagar. Então
a credencial que fica na VPS foi criada só com a permissão `writeFiles`.

**Por quê:** se alguém invadir o servidor, encontra essa credencial. Com ela, consegue subir
arquivos novos — e mais nada. Não baixa o histórico, não apaga nada.

Sem isso, o ataque padrão de ransomware moderno funciona: o invasor não só cifra seus dados,
como **apaga os backups primeiro**, usando a credencial que estava na própria máquina.

### O erro que essa decisão revelou

Aqui vale a parte mais instrutiva da tarefa.

A issue #44 registrou, com a frase **"confirmado na documentação"**, que o botão *Write Only*
do Backblaze concede apenas `writeFiles`. Todo o desenho foi construído sobre isso.

Quando o script perguntou à API o que a chave realmente podia fazer, a resposta foi:

```
writeBucketLogging, listBuckets, writeBucketEncryption, writeFiles,
writeBucketNotifications, deleteFiles, writeBucketReplications, writeBucketLifecycleRules
```

**`deleteFiles` estava lá.** No Backblaze, "Write Only" significa *"tudo menos ler"* — e
apagar não é ler. A credencial na VPS podia apagar todos os backups. Exatamente o cenário
que a decisão pretendia impedir.

A solução: o console web só oferece três botões prontos, mas a **API** aceita uma lista
explícita de permissões. O `b2-criar-chave.sh` usa isso para criar uma chave com
`capabilities: ["writeFiles"]` e nada mais. Depois da troca, a mesma pergunta responde:

```
Capacidades desta chave: writeFiles
```

**A lição não é "supus errado".** É que a suposição foi anotada como *"confirmado na
documentação"*, o que a transformou em fato citável. Ela sobreviveu três dias e virou base
de decisão de arquitetura. Escrever "confirmado" ao lado de algo que não foi verificado é
pior do que escrever "acho que".

### Quão comum no mercado

Menor privilégio é item de auditoria em qualquer empresa séria (SOC 2, ISO 27001). A
diferença entre saber recitar o princípio e saber **perguntar ao sistema o que a credencial
realmente pode fazer** é grande — e a segunda é a que evita incidente.

---

## Decisão 5 — o alarme avisa quando o backup **não** falha

Aqui está a inversão mais contraintuitiva do projeto.

### O jeito que todo mundo tenta primeiro

"O script manda e-mail quando falhar." Parece resolvido.

Agora liste os jeitos de um backup diário parar de acontecer:

1. O script rodou e deu erro — *o e-mail sai* ✅
2. Alguém desativou o agendamento sem querer — o script **não roda** ❌
3. O disco encheu e o agendador nem começou — **não roda** ❌
4. A máquina foi desligada pela Oracle — **não roda** ❌
5. A credencial expirou e o script morre cedo demais — talvez ❌

**Em quatro dos cinco casos o script não chega a falhar, porque não chega a rodar.** E
"não rodou" não gera e-mail nenhum. O silêncio é idêntico ao sucesso.

Pior: você fica *mais* tranquilo, porque não recebeu alerta. Backup que falha em silêncio é
pior do que não ter backup, porque produz confiança injustificada.

### A inversão

O script avisa quando **termina bem**. Um serviço fora da VPS espera esse aviso todo dia. Se
o aviso não chegar dentro do prazo, **ele** dispara o alarme.

```bash
curl -sS -m 15 --retry 3 -o /dev/null "${PING_URL}"
```

Uma linha, no fim de tudo, depois de o envio ter dado certo.

Isso cobre os cinco casos de uma vez — inclusive a máquina morta, que nenhum programa
rodando dentro dela poderia relatar.

O nome disso é **interruptor de homem morto** (*dead man's switch*): o mesmo mecanismo do
pedal que o maquinista precisa manter pressionado. Solte — por qualquer motivo, inclusive
desmaio — e o trem freia.

**O critério que importa:** o observador precisa estar **fora** da máquina observada.
Qualquer coisa dentro dela morre junto com ela.

### Quão comum no mercado

Muito comum em equipes de operação, quase desconhecido fora delas. Serviços como
Healthchecks.io, Cronitor e Dead Man's Snitch existem só para isso. Vale conhecer o conceito
pelo nome: é o tipo de coisa que separa quem já operou sistema de quem só escreveu código.

---

## Decisão 6 — quem apaga o backup velho é o balde, não o script

A regra é guardar 30 dias. Como a credencial da VPS não tem permissão para apagar, o script
**não pode** fazer essa limpeza.

A limpeza é configurada no próprio Backblaze, como *Lifecycle Rule*:

| Campo | Valor |
|---|---|
| `fileNamePrefix` | vazio (vale para tudo) |
| `daysFromUploadingToHiding` | 30 |
| `daysFromHidingToDeleting` | 1 |

O arquivo é ocultado 30 dias depois de enviado e apagado no dia seguinte.

Isso começou como limitação e acabou sendo o desenho melhor: a limpeza **não depende de o
script rodar**. Se o backup quebrar por um mês, os arquivos antigos continuam sendo
rotacionados corretamente, sem ninguém para lembrar.

---

## Decisão 7 — backup não testado não é backup

A frase é conhecida, mas quase sempre aplicada pela metade. "Testar" costuma virar "o
arquivo foi criado e tem tamanho razoável".

Isso não prova nada. Um arquivo cifrado de 12 KB pode conter um banco vazio, um banco
corrompido, ou o banco errado.

Este projeto verifica em três momentos diferentes:

**1. Antes de cifrar**, no `backup.sh`:

```bash
INTEGRIDADE="$(sqlite3 "${COPIA}" 'PRAGMA integrity_check;' | head -1)"
[[ "${INTEGRIDADE}" == "ok" ]] || erro "..."

USUARIOS="$(sqlite3 "${COPIA}" 'SELECT COUNT(*) FROM users;')" || erro "..."
```

Por que os dois: `integrity_check` responde *"este arquivo é um SQLite válido?"*. **Um banco
completamente vazio passa nesse teste.** Contar `users` responde a outra pergunta — *"este é
o banco certo?"*.

E precisa ser **antes** de cifrar: depois, não há como olhar dentro sem a chave privada, que
não existe naquela máquina. Se a verificação não acontece ali, não acontece nunca.

**2. Logo depois de cifrar:**

```bash
head -c 21 "${ARQUIVO}" | grep -q 'age-encryption.org' || erro "..."
```

Confere que o arquivo tem mesmo o cabeçalho do `age`. Pega o caso em que o comando "deu
certo" e produziu lixo.

**3. Na restauração**, o `restaurar.sh` não diz "OK" — ele **mostra o que veio dentro**:

```
TABELA         LINHAS
users          3
households     3
household_user 3
shopping_lists 0

Registro mais recente encontrado:
  2026-08-08 16:53:52 (UTC)
```

Essa última linha é a mais valiosa do relatório inteiro, e é a que quase ninguém coloca.
**Um backup íntegro e velho passa em todos os outros testes** e ainda assim significa que o
agendamento parou há semanas. Só a data revela isso.

### Quão comum no mercado

A frase é comum; a prática, não. Há casos famosos de empresas que descobriram o problema no
pior dia possível — o mais conhecido é o do GitLab, em 2017, que perdeu dados de produção e
descobriu ao vivo que **cinco** mecanismos de backup diferentes estavam falhando em silêncio.

---

## Como replicar em outro projeto

```bash
# 1. Instalar as ferramentas (Ubuntu 24.04)
sudo apt install -y age sqlite3

# 2. Gerar o par de chaves NA SUA MÁQUINA, nunca no servidor
age-keygen -o ~/.ssh/meu-backup.age-key
chmod 600 ~/.ssh/meu-backup.age-key
grep "public key" ~/.ssh/meu-backup.age-key      # esta vai para o servidor

# 3. TESTAR a chave antes de confiar nela — cifrar e decifrar de volta
echo "teste" | age -r age1SUA_CHAVE_PUBLICA -o /tmp/t.age
age -d -i ~/.ssh/meu-backup.age-key /tmp/t.age   # tem de imprimir: teste
rm /tmp/t.age

# 4. Guardar a chave privada FORA desta máquina também. Ela é insubstituível.

# 5. No servidor, o backup em quatro comandos
sqlite3 banco.sqlite ".backup '/tmp/copia.sqlite'"
sqlite3 /tmp/copia.sqlite 'PRAGMA integrity_check;'   # tem de dizer: ok
gzip -9 /tmp/copia.sqlite
age -r age1SUA_CHAVE_PUBLICA -o /tmp/copia.sqlite.gz.age /tmp/copia.sqlite.gz
```

O passo 3 não é opcional. Uma chave que nunca decifrou nada não é uma chave de backup — é um
arquivo com esperança dentro.

---

## Os cinco defeitos que apareceram, e o que cada um ensina

Três só apareceram quando o código encostou na realidade. Isso não é acidente: é o padrão.

### 1. A verificação de segurança acusava a própria documentação

O `backup.sh` procurava o texto `AGE-SECRET-KEY` no arquivo de configuração, para impedir
que a chave privada fosse parar no servidor. Só que o **arquivo-modelo** menciona esse texto
num comentário, ao explicar essa mesma verificação.

Resultado: quem seguisse a instrução — copiar o modelo — recebia
`[erro] Há uma chave PRIVADA age no .env` **sem ter chave privada nenhuma**.

Correção: ignorar as linhas de comentário antes de procurar.

**Lição:** o defeito não foi pego nos testes porque os testes usavam um arquivo de
configuração *inventado, mínimo*, em vez do modelo real que o sistema recebe na vida real.
Teste que exercita uma entrada que nunca acontece não testa nada.

### 2. Um campo informativo derrubava o backup inteiro

O script lia da resposta da nuvem a lista de permissões da credencial, para mostrar ao
operador. O caminho dentro do JSON estava errado, e o resultado foi um `KeyError` de Python
que **matava o script**.

Duas correções, e a segunda é a que importa:

- o caminho correto, tentando as variantes conhecidas da API;
- e, sobretudo: **um dado meramente informativo não pode derrubar a função principal.**
  Aquela lista serve para conferência humana. Se o formato da API mudar amanhã, perde-se o
  relatório — não o backup.

**Lição:** ao escrever qualquer passo, pergunte "se isto falhar, o que deveria acontecer?".
Nem toda falha merece parar tudo.

### 3. O relatório da restauração sumia em silêncio

A primeira versão contava as linhas de todas as tabelas numa consulta só (`UNION ALL`).
Bastava **uma** tabela não existir — coisa normal ao restaurar um backup anterior a uma
alteração de estrutura — para a consulta inteira falhar. O erro era engolido, e o relatório
simplesmente **não aparecia**.

Ou seja: exatamente na situação mais delicada, a informação mais importante desaparecia sem
avisar.

Correção: contar cada tabela sozinha, e mostrar `AUSENTE` quando faltar.

**Lição:** desconfie de `|| true` e de `2>/dev/null`. Eles transformam erro em silêncio, e
silêncio se parece com sucesso.

### 4. O arquivo de configuração estava legível por todos

O `api/.env` na VPS estava com permissão `664` — qualquer usuário da máquina podia lê-lo.
Ele guarda a chave da aplicação e agora também a credencial da nuvem.

Correção: o `deploy.sh` aplica `chmod 640` a cada publicação. **Automatizado, e não anotado
num checklist** — permissão que depende de alguém lembrar volta a errar sozinha.

### 5. "Write Only" não era write-only

Já detalhado acima. O defeito mais grave dos cinco, e o único que não estava no código:
estava numa anotação errada, feita com confiança.

---

## O que ficou registrado nas decisões

A emenda de 2026-08-10 ao **ADR-009** (`docs/decisoes.md`) formaliza três mudanças:

1. `age` assimétrico — a VPS guarda só a chave pública, gerada fora dela.
2. Retenção de 30 dias por *Lifecycle Rule* do balde, não pelo script.
3. Alerta invertido: interruptor de homem morto com observador externo.

O **Object Lock** — que impediria apagar backups com *qualquer* credencial, inclusive a
principal — continua adiado de propósito. O Backblaze só o oferece em modo *Compliance*, que
é **irreversível**: uma vez ativado, nem o dono nem o suporte da Backblaze conseguem apagar
um arquivo antes do prazo. Erro de configuração ali vira permanente. A decisão espera dados
reais de volume para ser tomada com números, não com estimativa.

---

## Resumo em cinco linhas

1. Banco de dados em uso não se copia com `cp`.
2. A chave que cifra não precisa ser a que decifra — e no servidor só deve existir a primeira.
3. Credencial de máquina recebe a permissão mínima; e pergunte ao sistema qual ela é de fato.
4. O alarme avisa quando o sucesso **não** chega, porque quem não roda não falha.
5. Backup que nunca foi restaurado é um arquivo, não um backup.
