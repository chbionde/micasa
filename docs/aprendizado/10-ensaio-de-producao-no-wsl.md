# Aprendizado 10 — Ensaiar a produção antes da produção

> O que foi feito: o ambiente de desenvolvimento mudou de Windows para Linux, e o script que monta o servidor de produção foi executado numa máquina descartável antes de tocar na VPS de verdade. O ensaio encontrou três defeitos — dois deles capazes de deixar o site rodando código velho sem que nada parecesse errado. Este documento é sobre uma ideia só: **infraestrutura também é código, e código que nunca foi executado não funciona.**

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

## 9. Idempotência: rodar duas vezes não pode quebrar

Uma palavra que aparece muito em infraestrutura: **idempotente**. Significa que executar a operação várias vezes tem o mesmo efeito de executá-la uma vez.

Por que isso importa tanto aqui: scripts de servidor são rodados de novo o tempo todo. A conexão SSH cai no meio. Você corrige uma linha e roda outra vez. O certificado falhou porque o DNS ainda não tinha propagado, e você tenta de novo em dez minutos. Um script que só funciona em máquina virgem é um script que funciona uma vez.

Na prática, é o que se vê nas verificações antes de agir:

```bash
if [[ ! -f /swapfile ]]; then        # só cria se não existe
if ! command -v composer; then       # só instala se falta
ln -sf ...                           # -f: sobrescreve sem reclamar
```

No ensaio isso foi testado de propósito: o `provision.sh` rodou **três vezes** e o `deploy.sh` **duas**, todas terminando com sucesso.

**Quão comum no mercado:** é o princípio fundador das ferramentas de automação de infraestrutura — Ansible, Puppet, Chef, Terraform são todas construídas em torno dele. A mentalidade é "declare o estado desejado", não "execute estes passos".

## 10. O que o ensaio efetivamente provou

Vale listar, porque é o retorno do investimento:

- O `nginx` sobe, e o roteamento de **origem única** funciona: `/` entrega a aplicação React, `/api/user` chega ao Laravel, `/casa` devolve a página em vez de erro 404
- O login por cookie de sessão funciona ponta a ponta: pedir o token de segurança, registrar, autenticar, consultar o usuário logado
- O banco SQLite ativa o modo WAL, e os arquivos auxiliares que ele cria nascem com as permissões certas
- A fila processa tarefas em segundo plano sem falhar
- O agendador roda de minuto em minuto
- Os dois scripts são idempotentes

Um detalhe do teste vale registro, porque quase virou um quarto "defeito": as primeiras tentativas de login devolviam "não autenticado" mesmo com os cookies corretos. A causa não era o servidor — era o `curl`. O Laravel decide se trata a requisição como vinda do próprio site olhando os cabeçalhos `Origin` e `Referer`, que **todo navegador envia automaticamente** e que o `curl` não envia a menos que você peça. Adicionados os cabeçalhos, o login passou.

A lição: quando uma ferramenta de linha de comando discorda do navegador, desconfie primeiro da ferramenta. Ela é honesta demais — só faz o que você mandou.

## 11. Como conferir

```bash
# serviços de pé
systemctl is-active nginx php8.4-fpm micasa-queue

# a aplicação responde
curl -sS http://SEU_DOMINIO/up          # health check do Laravel
curl -sS http://SEU_DOMINIO/            # a SPA

# a suíte de testes, igual ao CI
cd ~/code/micasa/api && vendor/bin/pint --test \
  && vendor/bin/phpstan analyse --memory-limit=1G --no-progress \
  && php artisan test
cd ~/code/micasa/web && npm run lint && npx tsc -b && npm run test
```

## 12. Resumo do que este documento ensinou

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
| Idempotência | Rodar de novo é o caso normal, não a exceção |

E a ideia que costura todas: **um script de infraestrutura é código.** Código não revisado tem bug; código nunca executado tem mais ainda. A diferença é que o bug do script de infraestrutura só aparece no dia em que você mais precisa que ele funcione.
