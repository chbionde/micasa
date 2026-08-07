# Aprendizado 10 — Ensaiar a produção antes da produção

> O que foi feito: o ambiente de desenvolvimento mudou de Windows para Linux, o script que monta o servidor de produção foi ensaiado numa máquina descartável, e só então rodou na VPS de verdade. O ensaio encontrou três defeitos. A VPS real encontrou outros três — e esses eram piores. O MiCasa entrou no ar em `https://micasa-bionde.duckdns.org`.
>
> Este documento é sobre duas ideias que puxam em direções opostas e são ambas verdadeiras: **infraestrutura é código, e código nunca executado não funciona** — mas também **ensaio não é produção, e quem confunde os dois troca um erro por outro.**

---

## 1. Por que sair do Windows

O projeto vinha sendo desenvolvido no Windows, com um contorno estranho em cada sessão: um comando de PowerShell para fazer o Bash enxergar o PHP. Contorno que se repete não é contorno, é sintoma.

A mudança foi para **WSL** — *Windows Subsystem for Linux*, um Linux de verdade rodando dentro do Windows, sem máquina virtual pesada nem dual boot. O terminal abre um Ubuntu completo; os arquivos do Windows continuam acessíveis.

Só que há uma pegadinha que decide se a experiência é boa ou terrível: **onde os arquivos moram.**

| Local | Caminho | Velocidade |
|---|---|---|
| Disco do Linux | `~/code/micasa` | rápido |
| Disco do Windows visto pelo Linux | `/mnt/c/Users/...` | **lento** |

A razão é que `/mnt/c` não é um disco — é uma tradução. Cada leitura de arquivo atravessa uma ponte entre os dois sistemas. Para abrir um arquivo, tudo bem. Para `composer install`, que mexe em dezenas de milhares de arquivos pequenos, a ponte vira o gargalo e a instalação pode levar minutos em vez de segundos.

Por isso o repositório foi clonado em `~/code/micasa`, no disco do Linux, e o clone antigo em `C:\Users\...` foi abandonado.

**Quão comum no mercado:** WSL é hoje o jeito padrão de desenvolver para Linux usando um PC Windows, e a regra "guarde o projeto no filesystem do Linux" é a primeira coisa que qualquer time ensina a quem chega. É tão comum que a própria Microsoft documenta.

## 2. O CI é a fonte da verdade sobre versões

Ao montar um ambiente do zero, a pergunta "qual versão do PHP eu instalo?" parece de gosto pessoal. Não é. Existe uma resposta certa, escrita no repositório.

O projeto tem *CI* — *Continuous Integration*, um servidor do GitHub que roda os testes a cada alteração enviada. E o arquivo que configura esse servidor diz exatamente qual versão usar:

```yaml
# .github/workflows/ci-api.yml
- name: Configurar PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: "8.4"
```

Se a sua máquina roda PHP 8.3 e o CI roda 8.4, você vive num mundo diferente do que julga o seu código. O teste passa aqui e falha lá — ou pior, passa nos dois e o comportamento difere em produção.

O mesmo vale para o Node: `ci-web.yml` diz `node-version: 22`. A máquina tinha o 24 instalado. Foi rebaixada para o 22.

**A regra:** ambiente local, CI e produção devem rodar a mesma versão. Quando divergem, o CI ganha — porque é ele que decide se o código entra.

**Quão comum no mercado:** universal, e é a origem do famoso *"na minha máquina funciona"*. Times resolvem isso com Docker (todo mundo roda o mesmo container), com arquivos de versão (`.nvmrc`, `.tool-versions`) ou com disciplina. A pior solução é nenhuma.

## 3. Quando o `composer.lock` decide por você

Havia uma tentação de economizar tempo: o Ubuntu 24.04 já vem com PHP 8.3, e o `composer.json` do projeto declara aceitar `"php": "^8.3"` — que significa "8.3 ou qualquer 8.x acima". Parecia que dava para ficar no 8.3 e seguir a vida.

Não dava. E quem disse isso foi o `composer.lock`:

```
symfony/http-foundation v8.1.2 requires php >=8.4.1
  -> your php version (8.3.6) does not satisfy that requirement.
```

Vale entender a diferença entre os dois arquivos, porque ela confunde muita gente:

| Arquivo | O que diz | Analogia |
|---|---|---|
| `composer.json` | "aceito PHP 8.3 ou superior" | a lista de compras |
| `composer.lock` | "e estas são exatamente as 200 bibliotecas instaladas, nestas versões" | a nota fiscal |

O `composer.json` era permissivo, mas uma das bibliotecas travadas no `.lock` — vinda junto do Laravel — exige 8.4.1. E `composer install` respeita o `.lock`, não o `.json`.

O que isso ensina: **o requisito real de um projeto é a soma dos requisitos de todas as suas dependências**, e essa soma vive no arquivo de trava. Ler só o `composer.json` te dá a intenção; o `.lock` te dá o fato.

**Quão comum no mercado:** o mesmo par existe em quase toda linguagem — `package.json`/`package-lock.json` no Node, `Gemfile`/`Gemfile.lock` no Ruby, `pyproject.toml`/`poetry.lock` no Python. E a regra é sempre a mesma: o arquivo de trava vai para o repositório e é ele que manda na instalação.

## 4. A ideia central: ensaiar

A VPS de produção tem 1 GB de RAM, é a máquina onde a família vai usar o sistema, e é acessada por SSH de um terminal só. Rodar nela um script de 300 linhas que nunca foi executado é apostar.

A alternativa é o **ensaio**: rodar o mesmo script numa máquina descartável primeiro. O WSL serve bem — é um Ubuntu de verdade, com o mesmo `systemd`, o mesmo `nginx`, os mesmos caminhos. Se algo quebra, você apaga e recomeça sem consequência.

Mas o ensaio tem um limite honesto, e vale ser explícito sobre ele:

| O que o ensaio **não** prova | Por quê |
|---|---|
| A regra de firewall | As regras que o script corrige vêm da imagem da Oracle. Fora dela, não há o que corrigir |
| A criação de swap | O WSL gerencia a memória virtual por conta própria e recusa o comando |
| O certificado HTTPS | O Let's Encrypt precisa alcançar a máquina pela internet; o WSL está atrás de NAT |
| O aperto de 1 GB de RAM | A máquina de ensaio tem RAM de sobra |

Aqui está o argumento que justifica o ensaio mesmo assim, e ele é mais sutil do que parece:

**O que o ensaio não cobre falha de forma barulhenta.** Firewall errado dá timeout. Certificado ausente dá erro de HTTPS na cara. Falta de memória mata o processo. São falhas que gritam, e o script pode ser rodado de novo.

**O que o ensaio cobre falha de forma silenciosa.** Permissão de arquivo errada, ordem de comandos invertida, cache que não recarrega — nada disso aparece como erro. Aparece como "estranho, jurava que tinha corrigido isso".

Ensaiar troca as falhas silenciosas por falhas barulhentas. Esse é o negócio todo.

**Quão comum no mercado:** é a espinha dorsal da disciplina de *Infrastructure as Code*. Times sérios mantêm ambientes de *staging* — cópias da produção usadas para ensaiar. Ferramentas como Terraform têm um comando `plan` que mostra o que aconteceria antes de acontecer. A ideia é sempre a mesma: separar o momento de descobrir o erro do momento de sofrer com ele.

## 5. Uma opção que só existe para teste

Para o script rodar fora da VPS, as quatro seções dependentes da máquina real precisavam ser desligáveis. A solução foi uma variável:

```bash
sudo PULAR_AJUSTES_DE_HOST=1 DOMINIO=micasa.localhost ./infra/provision.sh
```

E aqui vale reconhecer o que isso é: **um código de produção que carrega uma opção cuja única razão de existir é teste.** Isso é uma dívida, e foi assumida de olhos abertos.

A alternativa considerada era escrever um segundo script, só para ensaio. Foi descartada por um motivo específico: dois scripts divergem. Alguém corrige o de produção, esquece o de ensaio, e a partir daí o ensaio passa a testar um servidor que não existe mais — dando confiança falsa. **Um script com um `if` é pior que elegante; dois scripts que divergem em silêncio é pior que feio.**

A mitigação foi tornar o modo impossível de usar por acidente:

```
===============================================================
 ATENÇÃO: PULAR_AJUSTES_DE_HOST=1 — MODO DE ENSAIO

 Fuso horário, swap, iptables e certbot NÃO são aplicados.
 O que sai daqui NÃO é um servidor de produção válido.
 Nunca use esta variável na VPS.
===============================================================
```

O aviso aparece **duas vezes** — no início e no fim. A razão é prática: entre um e outro passam vários minutos de instalação de pacotes rolando a tela. Quem viu só o aviso do começo já esqueceu dele quando o script termina.

**Quão comum no mercado:** extremamente comum, e sempre desconfortável. Todo sistema grande tem *feature flags* e modos de teste convivendo com o código real. A boa prática é a que foi aplicada aqui: deixar berrante, documentar o porquê, e escrever no próprio código que é dívida.

## 6. Primeiro defeito: a receita fora de ordem

O `infra/README.md` é o *runbook* do projeto — o passo a passo que alguém segue para montar o servidor. Ele mandava:

```bash
# 3. Configure a aplicação
cp infra/env.production.example api/.env
cd api && php artisan key:generate       # <- morre aqui

# 4. Publique
./infra/deploy.sh                        # <- o composer install está aqui dentro
```

O `php artisan` é um programa PHP que depende das bibliotecas instaladas pelo `composer install`. Mas o `composer install` só acontecia no passo 4. Resultado no passo 3:

```
PHP Fatal error: Failed opening required '/var/www/micasa/api/vendor/autoload.php'
```

Ninguém tinha percebido porque ninguém tinha seguido o runbook do começo ao fim numa máquina limpa. Quem escreveu já tinha o `vendor/` na máquina.

A correção foi inverter a ordem. E aceitar uma redundância: o `deploy.sh` roda `composer install` de novo, logo depois. Na segunda vez ele não baixa nada e custa segundos — **preço baixo por um runbook que funciona sem alguém "saber o pulo do gato".**

**Quão comum no mercado:** documentação de instalação que só funciona para quem não precisa dela é quase uma lei da natureza. O antídoto é o que foi feito: executar o passo a passo numa máquina zerada, exatamente como está escrito, sem improvisar.

## 7. Segundo defeito: o `chmod` que matava o deploy

Este é o mais interessante dos três, e o mais perigoso.

O erro apareceu assim:

```
==> Ajustando permissões
chmod: changing permissions of 'storage/logs/laravel-2026-08-07.log': Operation not permitted
```

**A cadeia de causas.** No servidor, dois usuários diferentes escrevem nos mesmos diretórios:

- o **usuário de deploy** (`ubuntu`), que roda o `deploy.sh`
- o **`www-data`**, usuário do servidor web e do agendador de tarefas

O agendador roda de minuto em minuto como `www-data` e cria o arquivo de log do dia. Esse arquivo nasce **pertencendo ao `www-data`**. Depois, o `deploy.sh` roda como `ubuntu` e tenta ajustar as permissões de tudo:

```bash
chmod -R g+w storage bootstrap/cache database
```

E aqui está o detalhe fino: **o `chmod` exige que você seja o dono do arquivo** — e recusa mesmo quando a mudança pedida não mudaria nada. O log já estava com a permissão desejada. Não importou: o sistema confere o dono antes de olhar o resto, e devolve "operação não permitida".

**Por que isso é grave, e não só chato.** O script começa com esta linha:

```bash
set -Eeuo pipefail
```

O `-e` significa "aborte na primeira falha". Boa prática — mas veja *onde* o script abortava:

```
1. git pull                    ✅
2. composer install            ✅
3. migrate (altera o banco!)   ✅
4. config:cache, route:cache   ✅
5. chmod                       ❌ MORRE AQUI
6. reload do PHP-FPM              nunca acontece
7. restart da fila                nunca acontece
```

Agora junte com uma configuração feita lá no `provision.sh`:

```ini
opcache.validate_timestamps = 0
```

Isso manda o PHP **parar de verificar se os arquivos mudaram**. É um ganho real de desempenho em produção, mas tem um preço: o código novo só entra em vigor quando o PHP-FPM é recarregado — o passo 6, que nunca acontecia.

O resultado é o pior tipo de falha: **banco de dados migrado, código antigo rodando, e um script que parecia ter funcionado.** A mensagem de erro aparece no fim, discreta, entre linhas verdes de sucesso. Você atualiza a página, não vê a mudança, e vai procurar bug no seu código — que está certo.

A correção foi de uma palavra:

```bash
sudo chmod -R g+w storage bootstrap/cache database
```

O `root` pode alterar qualquer arquivo. E o script já usava `sudo` nas linhas seguintes, para recarregar os serviços — a inconsistência é que estava errada.

**Quão comum no mercado:** o conflito entre "usuário que publica" e "usuário que executa" é um dos clássicos da administração de servidores web. E a lição mais transferível não é sobre permissões: é sobre **a ordem dos passos num script que aborta na primeira falha**. Toda operação que deixa o sistema num estado intermediário — migrar banco, subir arquivo, invalidar cache — precisa ser pensada com a pergunta "e se ele morrer exatamente aqui?".

## 8. Terceiro defeito: um comentário que mentia

Este não quebra nada. Faz alguém perder uma tarde.

O arquivo de exemplo do `.env` de produção trazia:

```bash
LOG_LEVEL=warning
...
# Enquanto estiver como log, o link de redefinição só aparece em
# storage/logs — o que serve para testar, mas não para a família usar.
MAIL_MAILER=log
```

A ideia era razoável. Enquanto não há um serviço de e-mail configurado, o Laravel pode "enviar" e-mails escrevendo-os no arquivo de log. Assim dá para testar o "esqueci minha senha": você dispara, abre o log, copia o link.

Só que não funciona — e a razão está a dez linhas de distância, no mesmo arquivo.

Níveis de log são uma escala de importância. Do mais grave ao mais banal: `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`. Configurar `LOG_LEVEL=warning` significa *"grave `warning` e tudo que for mais grave; jogue fora o resto"*.

E o componente do Laravel que escreve e-mails no log faz isso em nível `debug` — o último da fila. Conferido no código-fonte:

```php
// Illuminate\Mail\Transport\LogTransport
$this->logger->debug((string) $string);
```

Então: o e-mail é gerado, entregue ao log, e **descartado antes de ser escrito**. O log fica vazio. Nada de errado aparece em lugar nenhum.

Foi confirmado na prática, não por leitura: com `warning`, o log ficou vazio; baixando para `debug` e repetindo o pedido, o link apareceu na hora.

```
http://micasa.localhost/redefinir-senha/6f979c5132a1cc6db66221cb370584ff...
```

A correção foi no comentário, não no comportamento — `LOG_LEVEL=warning` está certo para um servidor com 46 GB de disco, e log em `debug` guarda o corpo dos e-mails, incluindo tokens válidos. O que estava errado era a promessa. Agora o arquivo explica o conflito e dá a receita para baixar o nível temporariamente, com o aviso de voltar atrás.

**Quão comum no mercado:** comentário que descreve uma intenção antiga em vez do comportamento atual é uma das formas mais comuns de dívida técnica, e das mais traiçoeiras — código errado você desconfia, comentário errado você acredita. A regra prática: **comentário que faz uma afirmação verificável deve ser verificado**, não revisado no olho.

## 9. O que o ensaio efetivamente provou

Vale listar, porque é o retorno do investimento:

- O `nginx` sobe, e o roteamento de **origem única** funciona: `/` entrega a aplicação React, `/api/user` chega ao Laravel, `/casa` devolve a página em vez de erro 404
- O login por cookie de sessão funciona ponta a ponta
- O banco SQLite ativa o modo WAL
- A fila processa tarefas em segundo plano sem falhar
- O agendador roda de minuto em minuto

Um detalhe do teste vale registro, porque parecia defeito e não era: as primeiras tentativas de login devolviam "não autenticado" mesmo com os cookies corretos. A causa não era o servidor — era o `curl`. O Laravel decide se trata a requisição como vinda do próprio site olhando os cabeçalhos `Origin` e `Referer`, que **todo navegador envia automaticamente** e que o `curl` só envia se você pedir. Adicionados os cabeçalhos, o login passou.

Quando uma ferramenta de linha de comando discorda do navegador, desconfie primeiro da ferramenta. Ela é honesta demais — só faz o que você mandou.

## 10. E então a VPS real achou outros três

Aqui a história vira, e é a parte mais importante deste documento.

O ensaio deu confiança. Três defeitos corrigidos, tudo verde, dois scripts rodando repetidamente sem erro. A conclusão natural seria "o script está pronto". **Estava errada.** A VPS real revelou mais três defeitos, e os três eram piores que os anteriores.

### 10.1. O banco que vira somente-leitura no segundo deploy

O sintoma:

```
attempt to write a readonly database
```

E o desconcertante: o arquivo do banco estava gravável, e o diretório tinha exatamente as permissões que o manual mandava conferir.

A causa está numa distinção fina entre **dono** e **grupo**. Os diretórios foram criados com uma marca chamada *setgid*, que faz todo arquivo novo herdar o **grupo** da pasta. Ótimo — mas setgid não herda o **dono**.

Quando o servidor web (`www-data`) atende uma requisição, o SQLite cria dois arquivos auxiliares do modo WAL, `database.sqlite-wal` e `-shm`. Eles nascem pertencendo ao `www-data`. Já o usuário que faz o deploy é o `ubuntu` — e ele **não era membro do grupo `www-data`**. Resultado:

| Arquivo | Dono | O que o `ubuntu` podia |
|---|---|---|
| `database.sqlite` | `ubuntu` | escrever (é o dono) |
| `database.sqlite-wal` | `www-data` | **só ler** |

E o SQLite precisa escrever no `-wal` para qualquer escrita. Daí o "banco somente-leitura" com o banco perfeitamente gravável.

O detalhe cruel: **isso não aparece no primeiro deploy.** Enquanto o servidor web não atendeu nenhuma requisição, os arquivos WAL nem existem. O problema nasce depois que o site recebe a primeira visita — ou seja, aparece no segundo deploy em diante, que é justamente quando você já confia no processo.

A correção foi colocar o usuário de deploy no grupo: `usermod -aG www-data ubuntu`. Com uma pegadinha adicional que vale saber: **mudança de grupo só vale em sessão nova.** Você precisa sair e entrar no SSH; até lá, o comando continua falhando e parece que a correção não funcionou.

**Quão comum no mercado:** o par dono/grupo entre "quem publica" e "quem executa" é um clássico de servidor web, e a variante com SQLite + WAL pega gente experiente porque o arquivo que aparece no erro não é o arquivo que causou o erro.

### 10.2. O erro de teste que deixou isso passar

Esta subseção existe porque o erro foi meu e é instrutivo.

No ensaio, eu quis verificar exatamente isso — se o usuário de deploy conseguia escrever no banco com os arquivos WAL presentes. Rodei:

```bash
php artisan migrate --force
```

E a resposta foi:

```
INFO  Nothing to migrate.
```

Interpretei como sucesso. Mas "nada para migrar" significa que o comando **não escreveu nada**. Eu tinha executado um teste que não exercitava o que eu afirmava estar testando, e registrei um "funciona" que não tinha sido demonstrado.

O teste correto, feito depois na VPS, foi uma escrita de verdade — apagar um registro e conferir que o banco aceitou.

**A lição, que vale para todo teste:** um teste que passa sem exercitar o comportamento não é um teste fraco, é um **teste falso** — pior que nenhum, porque produz confiança. Antes de aceitar um verde, pergunte: *se o comportamento estivesse quebrado, este teste teria ficado vermelho?* Se a resposta não for um sim claro, o teste não vale.

**Quão comum no mercado:** muito, e tem nome. É por isso que existe *mutation testing* — uma técnica que estraga o seu código de propósito para ver se algum teste percebe. Testes que continuam verdes com o código quebrado são exatamente este defeito.

### 10.3. Cabeçalhos de segurança que chegavam no lugar errado

A configuração do nginx declarava três cabeçalhos de proteção no nível do servidor — entre eles o `X-Frame-Options: DENY`, que impede a página de ser embutida num iframe (a base do golpe de *clickjacking*).

Medindo em produção, o resultado foi o inverso do pretendido:

| Rota | Cabeçalhos |
|---|---|
| `/up`, `/api/*` — respostas **JSON** | ✅ presentes |
| `/` — a página **HTML** | ❌ ausentes |
| `/assets/*.js`, `*.css` | ❌ ausentes |

A causa é uma regra do nginx que contraria a intuição: **`add_header` não é aditivo.** Se um bloco `location` declara qualquer `add_header` próprio, ele **descarta todos** os herdados do nível acima. Não soma — substitui.

Como os blocos que servem o HTML e os assets declaravam um `Cache-Control` próprio, perdiam os três de segurança junto. Sobrava proteção apenas nas respostas JSON, onde clickjacking não faz sentido, e faltava exatamente na página que pode ser enquadrada.

A correção foi repetir os três cabeçalhos nos blocos que têm `add_header` próprio — feio, mas é como o nginx funciona.

**Quão comum no mercado:** essa regra específica é uma das pegadinhas mais conhecidas do nginx e continua pegando gente todo ano. O padrão maior — *"herança que some quando você sobrescreve"* — aparece em CSS, em configuração de logging, em permissões. Vale a suspeita sempre que houver herança e sobrescrita no mesmo lugar.

### 10.4. O script "idempotente" que derrubava o site

Este foi o pior, e é irônico: o próprio documento tinha uma seção celebrando a idempotência dos scripts.

O `provision.sh` declarava, no cabeçalho: *"É idempotente: rodar duas vezes não quebra nada."* Rodei duas vezes na VPS. **A porta 443 fechou e o site caiu para HTTP puro.**

A cadeia:

1. O script gera a configuração do nginx a partir de um modelo versionado. O modelo só sabe de HTTP (`listen 80`).
2. O certbot, ao emitir o certificado, **edita esse mesmo arquivo**, acrescentando as linhas de HTTPS e o redirecionamento.
3. Na segunda execução, o passo 1 regenera o arquivo do zero — apagando o que o certbot escreveu.
4. E o passo do certificado dizia apenas *"certificado já existe, pulando"* — então nada recolocava o HTTPS.

E não foi degradação suave. A aplicação usa cookie de sessão marcado como `Secure`, que **o navegador se recusa a enviar por HTTP**. Sem HTTPS, ninguém consegue autenticar. O site não ficou pior; ficou inutilizável.

O agravante: a tabela de diagnóstico do próprio projeto manda *"rode o `provision.sh` de novo"* como solução para um outro problema. O manual instruía o usuário a executar a ação que derrubava o site.

A correção foi trocar o "pular" por um comando que reinstala a configuração a partir do certificado que já está no disco (`certbot install`) — sem contactar a autoridade certificadora e sem gastar cota de emissão. Depois, o teste que importava: rodar duas vezes seguidas e confirmar que o HTTPS sobrevive.

**Quão comum no mercado:** a colisão entre "arquivo gerado por template" e "arquivo editado por outra ferramenta" é um problema estrutural de automação, não um descuido pontual. Ferramentas maduras lidam com isso separando o que é gerado do que é acrescentado — no nginx, por exemplo, mantendo o bloco SSL num arquivo `include` que o template não toca.

E a lição maior: **"é idempotente" é uma afirmação testável.** Enquanto ninguém rodar duas vezes e conferir o resultado, é só uma intenção escrita num comentário.

## 11. Então o ensaio valeu a pena?

Valeu, e a resposta honesta é mais interessante que um sim.

O ensaio pegou **três** defeitos. A VPS pegou **outros três**. Se olharmos só para os números, parece que o ensaio entregou metade do que prometia. Mas veja *quais* defeitos cada ambiente pegou:

| Defeito | Onde apareceu | Podia ter aparecido no ensaio? |
|---|---|---|
| Ordem do runbook | ensaio | sim |
| `chmod` matando o deploy | ensaio | sim |
| Log do e-mail descartado | ensaio | sim |
| Banco somente-leitura | VPS | **sim** — falhou por erro de teste meu |
| Cabeçalhos de segurança | VPS | **sim** — bastava ter medido |
| HTTPS derrubado | VPS | **não** — depende do certbot, que exige domínio público |

Ou seja: dos três que escaparam, **apenas um era realmente impossível de pegar no ensaio.** Os outros dois escaparam porque eu não olhei — um por um teste que não testava, outro por não ter medido os cabeçalhos.

Isso muda a conclusão. O limite do ensaio não foi principalmente o ambiente; foi **o cuidado de quem ensaiou**. É uma constatação mais útil do que "ensaio não pega tudo", porque aponta para algo acionável: a maior parte da diferença estava ao alcance de um teste melhor.

E a parte que o ambiente realmente limita continua valendo o alerta do início: HTTPS, firewall da nuvem e pressão de memória só existem de verdade na máquina de verdade. Por isso o ensaio **antecede** a produção, nunca a substitui.

## 12. Idempotência: rodar duas vezes não pode quebrar

Uma palavra que aparece muito em infraestrutura: **idempotente**. Significa que executar a operação várias vezes tem o mesmo efeito de executá-la uma vez.

Por que isso importa tanto aqui: scripts de servidor são rodados de novo o tempo todo. A conexão SSH cai no meio. Você corrige uma linha e roda outra vez. O certificado falhou porque o DNS ainda não tinha propagado, e você tenta em dez minutos. Um script que só funciona em máquina virgem é um script que funciona uma vez.

Na prática, é o que se vê nas verificações antes de agir:

```bash
if [[ ! -f /swapfile ]]; then        # só cria se não existe
if ! command -v composer; then       # só instala se falta
ln -sf ...                           # -f: sobrescreve sem reclamar
```

Mas a seção 10.4 mostrou o outro lado: essas verificações dão a *aparência* de idempotência sem garanti-la. O script tinha todas elas e mesmo assim derrubava o site na segunda execução, porque o problema não estava em nenhum comando isolado — estava na interação entre dois passos distantes um do outro.

**Idempotência não se lê no código, se mede executando.**

**Quão comum no mercado:** é o princípio fundador das ferramentas de automação — Ansible, Puppet, Chef, Terraform são todas construídas em torno dele. A mentalidade é "declare o estado desejado", não "execute estes passos".

## 13. Como conferir

```bash
# serviços de pé (na VPS)
systemctl is-active nginx php8.4-fpm micasa-queue

# a aplicação responde
curl -sS https://micasa-bionde.duckdns.org/up     # health check do Laravel
curl -sS https://micasa-bionde.duckdns.org/       # a SPA

# HTTP tem de redirecionar para HTTPS
curl -sI http://micasa-bionde.duckdns.org/ | head -1     # espera-se 301

# os cabeçalhos de segurança chegam no HTML, não só na API
curl -sI https://micasa-bionde.duckdns.org/ | grep -i x-frame

# a suíte de testes, igual ao CI
cd ~/code/micasa/api && vendor/bin/pint --test \
  && vendor/bin/phpstan analyse --memory-limit=1G --no-progress \
  && php artisan test
cd ~/code/micasa/web && npm run lint && npx tsc -b && npm run test
```

## 14. Uma nota sobre estar no ar

Minutos depois de o certificado ser emitido, o servidor já registrava visitas de endereços desconhecidos, de faixas de datacenter.

Não é coincidência nem invasão. Toda emissão de certificado é publicada num registro público chamado **Certificate Transparency** — criado para que ninguém consiga emitir um certificado do seu domínio às escondidas. O efeito colateral é que o registro também é varrido continuamente por robôs em busca de domínios novos.

A consequência prática: **um domínio novo não é secreto.** Não existe "ninguém sabe o endereço ainda". A partir do primeiro certificado, o endereço é público e será visitado. Se houver cadastro aberto, alguém vai cadastrar.

No MiCasa isso está sob controle por duas razões que já vinham do projeto: as rotas de autenticação têm limite de tentativas por minuto, e o isolamento por casa garante que uma conta desconhecida não enxergue nada de outra. Mas é bom saber que a proteção veio de decisões tomadas antes, não da obscuridade do endereço.

**Quão comum no mercado:** universal e frequentemente ignorado. Muita equipe trata um ambiente de homologação como privado porque "o link não foi divulgado" — e ele está em índices públicos desde o primeiro certificado.

## 15. Resumo do que este documento ensinou

| Ideia | Em uma frase |
|---|---|
| Onde os arquivos moram | No WSL, projeto no disco do Linux; `/mnt/c` é ponte, não disco |
| O CI manda nas versões | Se local e CI divergem, o local está errado |
| `.json` pede, `.lock` manda | O requisito real é a soma das dependências |
| Ensaiar | Trocar falha silenciosa por falha barulhenta, numa máquina descartável |
| Dívida assumida | Uma opção só-de-teste em código de produção, gritando que é isso |
| Runbook não executado | Documentação só funciona depois de alguém segui-la numa máquina limpa |
| `set -e` e ordem dos passos | Pergunte sempre "e se morrer exatamente aqui?" |
| Comentário verificável | Deve ser verificado, não revisado no olho |
| Dono ≠ grupo | `setgid` herda o grupo, nunca o dono — e é aí que o WAL trava |
| Teste que não testa | Se o comportamento quebrasse, este teste ficaria vermelho? |
| Herança que some | No nginx, `add_header` do filho descarta os do pai |
| Idempotência | Não se lê no código; mede-se executando duas vezes |
| Domínio novo não é secreto | Certificate Transparency publica todo certificado emitido |

E a ideia que costura todas: **um script de infraestrutura é código.** Código não revisado tem bug; código nunca executado tem mais ainda.

Mas o fecho honesto deste documento é outro, e foi aprendido do jeito difícil: **executar não basta — é preciso conferir o resultado certo.** Três dos seis defeitos passaram por um ensaio inteiro, e dois deles não escaparam por limitação do ambiente. Escaparam porque um teste respondeu "nada a fazer" e eu li "funcionou".
