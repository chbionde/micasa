# Aprendizado 09 — A VPS de graça, o firewall invisível e como a SPA e a API passam a morar juntas

> O que foi construído: a primeira máquina de produção do MiCasa, num servidor gratuito da Oracle Cloud, e o script que a monta do zero. Junto vieram duas decisões de arquitetura, um erro de rota que só aparece em produção e várias armadilhas que não estão em nenhum tutorial. Este documento é o único da série que trata de **operação**: onde o software realmente roda depois que o código está pronto.

---

## 1. Por que isso demorou tanto para acontecer

O plano original dizia "deploy no dia 1". A realidade foi: Fatias 0, 1, 1.5 e metade da 2 prontas, com **nada em produção**. O projeto acumulou três fatias e meia de código que nunca tinham saído da máquina de desenvolvimento.

Isso é comum e é armadilha. O raciocínio parece razoável — *"deploy é chato, faço quando tiver algo pronto"* — mas o risco cresce com o tamanho do que nunca subiu. Quem deixa o primeiro deploy para o mês 3 não faz um deploy: faz uma migração. Se algo da fundação estivesse errado (banco, autenticação, estrutura de pastas), a descoberta viria agora, com muito mais código em cima.

O critério de pronto deste projeto (*Definition of Done*) exige "está em produção e alguém da casa usou". Pelo próprio critério do projeto, **nenhuma fatia estava pronta** — só "quase pronta". Colecionar "quase pronto" é a forma mais silenciosa de um projeto pessoal morrer.

**Quão comum no mercado:** universal. Times maduros combatem isso com *continuous deployment* — cada merge na `main` vai para produção automaticamente, então nunca existe um estoque de código não publicado. Times imaturos têm "a semana do deploy", que é sempre a pior semana do trimestre.

## 2. A conta gratuita: o que a Oracle dá e o que ela tira

A Oracle Cloud tem um nível "Always Free" — recursos permanentemente gratuitos, sem prazo. Dois tipos de máquina:

| Tipo | O que é | Situação |
|---|---|---|
| **Ampere A1** (ARM) | até 2 OCPU e 12 GB de RAM | disputadíssima; erro "out of host capacity" é rotina |
| **E2.1.Micro** (AMD) | 2 máquinas de 1/8 de OCPU e 1 GB cada | quase sempre disponível |

As duas cotas são **separadas**: usar a AMD não gasta nada da ARM.

Dois fatos descobertos no caminho, ambos contrariando o que "todo mundo sabe":

**A cota ARM foi cortada pela metade em junho de 2026** — era 4 OCPU / 24 GB, virou 2 / 12, sem anúncio público. A partir de 18/08/2026 a Oracle passa a **terminar automaticamente** instâncias acima da cota. Tutoriais que ensinam a criar uma máquina 4/24 hoje entregam uma máquina que morre em algumas semanas.

**A região de origem (*home region*) é escolhida na criação da conta e nunca mais muda.** Recursos gratuitos só existem nela. Errar significa apagar a conta e recriar — o que a Oracle costuma barrar. Escolhemos `sa-vinhedo-1` (Vinhedo, SP): mesma latência prática de São Paulo, fila de capacidade menor.

E o mais contraintuitivo: **instância ociosa pode ser recuperada**. O critério oficial é, ao longo de 7 dias, CPU no 95º percentil abaixo de 20% **e** rede abaixo de 20% **e** memória abaixo de 20% — sendo que a de memória só vale para máquinas A1. Ou seja, a AMD é julgada por **duas** condições em vez de três, o que a torna *mais* suscetível, não menos.

Então por que escolher a AMD? Não por imunidade — por **recuperabilidade**. Instância recuperada é parada, e religar exige capacidade disponível. Capacidade de AMD existe; de A1 é justamente o que falta. Na A1, "parada" pode virar "parada por semanas".

**Quão comum no mercado:** conhecer as regras finas do nível gratuito de um provedor é habilidade de pessoa que opera infraestrutura, não de quem só programa. O padrão de raciocínio — *"o que este contrato me tira, e não só o que me dá?"* — vale para qualquer fornecedor.

## 3. IP efêmero: o detalhe que quebra tudo depois

Uma máquina nova nasce com IP público **efêmero**: ele é devolvido quando a instância é parada e religada. Combine com a recuperação por ociosidade da seção anterior e o resultado é previsível — um dia o site cai e o endereço mudou.

Isso importa porque duas coisas ficam presas ao endereço:

- **DNS** — o nome `micasa-bionde.duckdns.org` aponta para um número
- **Certificado TLS** — emitido para o nome, validado por uma requisição que chega naquele número

A solução é converter o IP para **reservado**. E aqui há uma pegadinha de console: não existe "converter". A Oracle libera o efêmero e aloca um reservado novo, **com outro número**. Ou seja: reserve o IP **antes** de configurar DNS e certificado, ou você refaz os dois.

**Quão comum no mercado:** universal, e cada nuvem cobra diferente por isso. A AWS cobra US$ 0,005/hora por IPv4 público, esteja ele em uso ou não. A DigitalOcean cobra US$ 5/mês por IP reservado ocioso. A Oracle não cobra. Ler a política de preço de IP antes de reservar dez deles é economia real.

## 4. O firewall que não aparece no console

Este é o item que mais consome tempo de quem sobe Ubuntu na Oracle pela primeira vez, e vale entender porque o mesmo padrão aparece em muitos outros lugares.

Existem **duas** camadas de firewall entre a internet e a aplicação:

```
internet
   │
   ├─► Lista de Segurança da VCN   ← configurada no console da Oracle
   │
   ├─► iptables dentro da máquina  ← configurado por SSH, invisível no console
   │
   └─► nginx
```

As imagens Ubuntu da Oracle vêm com regras `iptables` que **descartam tudo além de SSH**. Você libera as portas 80 e 443 no console, vê tudo verde, e a porta 80 continua dando *timeout*. O console não tem como avisar: ele não enxerga dentro da máquina.

No `provision.sh`:

```bash
for porta in 80 443; do
  if ! iptables -C INPUT -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT 2>/dev/null; then
    # -I INPUT 1 insere no TOPO, antes da regra REJECT que fecha a cadeia
    iptables -I INPUT 1 -p tcp --dport "$porta" -m conntrack --ctstate NEW -j ACCEPT
  fi
done
netfilter-persistent save
```

Três detalhes que fazem diferença:

- `iptables -C` **testa** se a regra já existe. Sem esse teste, rodar o script duas vezes empilha regras duplicadas.
- `-I INPUT 1` insere no topo. `iptables` avalia em ordem e para na primeira que casa — inserir no fim, depois da regra que rejeita tudo, não teria efeito nenhum.
- `netfilter-persistent save` grava em disco. Sem isso, a regra some no próximo *reboot*, e o problema volta semanas depois, aparentemente do nada.

**Quão comum no mercado:** o conceito de camadas independentes de firewall é padrão em toda nuvem — *security group* na AWS, *firewall rule* no GCP, *network security group* no Azure, todos somados ao firewall do sistema operacional. A pergunta de depuração — *"qual das camadas está bloqueando?"* — é a mesma em qualquer uma.

## 5. Um servidor com 1 GB de RAM muda decisões

1 GB é pouco. Três ajustes que existem só por causa disso:

**Swap.** Espaço em disco usado como extensão da memória. É lento, mas a alternativa é o kernel matar o PHP no primeiro pico. `vm.swappiness=10` diz ao kernel para só recorrer ao disco quando realmente faltar RAM — swap como rede de proteção, não como memória de trabalho.

**PHP-FPM em `ondemand`.** O PHP-FPM mantém um conjunto de processos prontos para atender requisições. O modo padrão (`dynamic`) mantém alguns vivos o tempo todo. Numa casa com 4 pessoas, o tráfego é rajada curta com longos silêncios — `ondemand` mantém **zero** processos parados e sobe sob demanda. Troca-se alguns milissegundos na primeira requisição por ~100 MB de RAM livre o dia inteiro.

**O build do front não roda no servidor.** `npm run build` (Vite + TypeScript) precisa facilmente de mais de 1 GB. A construção acontece fora, e só o resultado — a pasta `dist/` — é enviada. Isso não é gambiarra: separar *build* de *deploy* é o desenho correto em qualquer tamanho de projeto. Servidor de produção não deveria ter compilador.

**Quão comum no mercado:** dimensionar aplicação para a máquina é rotina. Já a regra "servidor de produção não compila" é consenso — é a base de *pipelines* de CI/CD, de imagens Docker e de qualquer artefato versionado.

## 6. Origem única: a SPA e a API no mesmo endereço

Aqui entra a decisão de arquitetura mais importante deste documento (registrada como ADR-020).

Em desenvolvimento, o MiCasa roda em **duas origens**: o Vite serve o React em `localhost:5173` e o Laravel responde em `localhost:8000`. "Origem" é a combinação protocolo + domínio + porta, e o navegador trata origens diferentes com desconfiança — daí CORS, daí a configuração de domínios *stateful* do Sanctum.

Em produção havia duas opções:

| | Como fica | Custo |
|---|---|---|
| **Origem única** | um endereço; o nginx entrega o React e repassa ao PHP só os caminhos do servidor | um certificado, zero CORS, cookie trivialmente do mesmo site |
| **Dois endereços** | `micasa...` para o front, `api.micasa...` para a API | espelha o deploy de mercado; exige CORS, dois certificados e mais peças |

Escolhemos **origem única**. O raciocínio: o aprendizado de CORS e Sanctum já foi colhido no ambiente de desenvolvimento, que **continua** com duas origens. Levar isso para produção adicionaria peças que quebram sem ensinar nada novo — num servidor de 1 GB.

O nginx implementa a fronteira assim:

```nginx
# Caminhos do servidor
location ~ ^/(api|sanctum|up|login|register|logout|forgot-password|reset-password)(/|$) {
    root /var/www/micasa/api/public;
    try_files $uri /index.php?$query_string;
}

# Todo o resto é da SPA
location / {
    try_files $uri $uri/ /index.html;
}
```

O `try_files $uri $uri/ /index.html` do último bloco é o que faz uma SPA funcionar. Quando alguém abre `micasa.../casa` direto na barra de endereço, o navegador pede `/casa` ao servidor — que não tem arquivo nenhum com esse nome. Sem essa linha, o resultado é 404. Com ela, o nginx devolve o `index.html`, o React carrega e o *React Router* decide o que mostrar **no navegador**. Roteamento do lado do cliente exige essa cumplicidade do servidor.

**Quão comum no mercado:** as duas arquiteturas convivem. Origem única é o padrão de aplicações servidas por um servidor só (e o que Rails, Django e Laravel fazem há décadas). Origem separada é o padrão quando o front vai para uma CDN — Vercel, Netlify, CloudFront. Saber configurar as duas, e explicar por que escolheu uma, é assunto de entrevista.

## 7. O bug que só existiria em produção

Ao desenhar o nginx, uma colisão apareceu — e ela **não existe em desenvolvimento**, porque lá são duas origens.

O nginx roteia por **caminho**, não por método HTTP. E havia dois donos para o mesmo caminho:

| Caminho | Laravel | SPA |
|---|---|---|
| `POST /login` | autentica | — |
| `GET /login` | — | mostra a tela de login |

Mandar `/login` para o PHP quebraria a tela. Mandar para a SPA quebraria o login. Em desenvolvimento nada disso aparece: `localhost:5173/login` e `localhost:8000/login` são endereços diferentes.

Curiosamente, **só essa** colidiu. As demais escaparam por acaso: a SPA usa nomes em português (`/registrar`, `/esqueci-senha`) e o Laravel, inglês (`/register`, `/forgot-password`).

Duas saídas foram consideradas:

1. **Mover as rotas de sessão do Laravel para `/api`** — cria uma fronteira estrutural, não uma convenção. Mas tira as rotas do grupo `web` (que traz sessão e proteção CSRF) para o grupo `api`, onde a sessão depende da configuração do Sanctum. É mexer em autenticação na véspera do primeiro deploy.
2. **Renomear a rota de navegação da SPA para `/entrar`** — não toca em nenhuma chamada de API, porque o que muda é só o endereço da tela.

Escolhemos a **2**. E ela trouxe um ganho não planejado: `/login` era a única rota da SPA em inglês, no meio de `/registrar`, `/esqueci-senha`, `/casa` e `/conta`.

A distinção que fez o renome ser barato:

```tsx
// ROTA DE NAVEGAÇÃO — mudou. É o endereço da tela no navegador.
{ path: '/entrar', element: <LoginPage /> }

// CHAMADA DE API — não mudou. É o endpoint do Laravel.
await api.post('/login', { email, password })
```

São duas coisas com o mesmo nome e propósitos diferentes. Confundi-las teria quebrado a autenticação inteira.

**Quão comum no mercado:** muito. Todo projeto com front e back no mesmo domínio precisa de um contrato de quais caminhos pertencem a quem — e quem não escreve esse contrato o descobre por colisão, geralmente em produção.

## 8. Uma armadilha de configuração pega antes de doer

O cliente HTTP do front estava assim:

```ts
baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000'
```

Faz sentido em desenvolvimento. Mas o Vite **embute** essas variáveis no código durante a compilação. Um build de produção sem `VITE_API_URL` definida geraria um site que tenta falar com `localhost:8000` — ou seja, com a máquina de quem está visitando. O site abriria normalmente e **nada funcionaria**, sem erro nenhum no servidor.

O primeiro reflexo foi criar um arquivo `.env.production`. Mas o `.gitignore` do projeto bloqueia `.env.*` — proteção deliberada contra vazar segredo — então o arquivo simplesmente não seria versionado, e o problema voltaria no próximo clone.

A correção foi tornar o **padrão** seguro:

```ts
baseURL: import.meta.env.VITE_API_URL ?? (import.meta.env.DEV ? 'http://localhost:8000' : '')
```

`import.meta.env.DEV` é um booleano que o Vite resolve em tempo de compilação. Em produção, `baseURL` vazia faz o axios usar caminhos relativos — que, em origem única, é exatamente o certo. E o domínio não fica embutido no build.

A lição atravessa a linguagem: **quando um valor errado é silencioso, o padrão precisa ser o seguro.** Configuração que só funciona se alguém lembrar de preenchê-la é bug agendado.

**Quão comum no mercado:** *"secure by default"* e *"safe defaults"* são princípios de projeto de API bem estabelecidos. A variante específica — variável de build embutida no artefato — é uma das confusões mais frequentes de quem começa com Vite, Next.js ou Create React App.

## 9. SQLite em modo WAL, por configuração e não por comando

O SQLite grava, por padrão, com *rollback journal*: enquanto alguém escreve, ninguém lê. Com o site, o worker da fila e o *scheduler* mexendo no mesmo arquivo, isso vira `database is locked`.

O modo **WAL** (*Write-Ahead Logging*) inverte a lógica: a escrita vai para um arquivo lateral e os leitores continuam lendo a versão anterior sem esperar. Leitura concorrente com escrita passa a funcionar.

A maioria dos tutoriais manda rodar um comando uma vez:

```bash
sqlite3 database.sqlite "PRAGMA journal_mode=WAL;"
```

Funciona, mas é frágil: some se o banco for recriado, não aparece em lugar nenhum do repositório, e quem restaurar um backup não vai lembrar. O Laravel oferece caminho melhor — `config/database.php` aceita esses ajustes:

```php
'busy_timeout' => env('DB_BUSY_TIMEOUT'),
'journal_mode' => env('DB_JOURNAL_MODE'),
'synchronous'  => env('DB_SYNCHRONOUS'),
```

E o `.env` de produção liga:

```
DB_JOURNAL_MODE=WAL
DB_BUSY_TIMEOUT=5000
DB_SYNCHRONOUS=NORMAL
```

Agora é configuração versionada, aplicada em toda conexão — web, fila e *scheduler* — e sobrevive a qualquer recriação do banco.

**Um detalhe de permissão que custa horas:** o WAL cria `database.sqlite-wal` e `database.sqlite-shm` **ao lado** do banco. Isso significa que o processo precisa de escrita **no diretório**, não só no arquivo. O sintoma é `attempt to write a readonly database` com o arquivo aparentemente com as permissões certas.

**Quão comum no mercado:** WAL é o padrão recomendado para SQLite em qualquer aplicação com concorrência. O padrão maior — *"configuração declarada e versionada vale mais que comando manual"* — é a base de *infrastructure as code*.

## 10. Fila com systemd, scheduler com cron

Duas coisas parecidas que resolvem problemas diferentes:

**Fila** — trabalho pesado tirado do caminho da requisição. Enviar e-mail de recuperação de senha demora; ninguém deve esperar por isso com a página travada. Roda como serviço do `systemd`:

```ini
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
```

O `--max-time=3600` é o detalhe interessante: o worker **sai sozinho** a cada hora e o systemd o reinicia. Resolve dois problemas de uma vez — vazamento de memória em processo longo (crítico com 1 GB) e o fato de que um processo PHP carrega o código na memória ao iniciar, então **código novo só entra em vigor com reinício**. Sem isso, um deploy passaria despercebido pela fila.

**Scheduler** — tarefas por horário (lembretes de vencimento, na Fatia 6). Uma única entrada de cron:

```
* * * * * www-data cd /var/www/micasa/api && /usr/bin/php artisan schedule:run
```

Roda a cada minuto e o **Laravel** decide o que executar, a partir de `routes/console.php`. Essa inversão é o ponto: em vez de dezenas de linhas no cron do servidor, uma linha só, e o agendamento fica versionado no repositório junto com o código.

**Quão comum no mercado:** exatamente este par — supervisor de processo para filas, uma entrada de cron para o scheduler — é o desenho padrão de Laravel em produção. A alternativa comum ao systemd é o Supervisor.

## 11. O deploy, e o passo que todo mundo esquece

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache route:cache view:cache event:cache
sudo systemctl reload php8.4-fpm      # ← este
sudo systemctl restart micasa-queue
```

O `reload` do PHP-FPM é **obrigatório**, e o motivo está no `provision.sh`:

```ini
opcache.validate_timestamps = 0
```

O *opcache* guarda o PHP já compilado em memória. Com `validate_timestamps=1` (o padrão), ele confere a data de modificação de cada arquivo a cada requisição — correto, porém desperdício em produção, onde os arquivos só mudam no deploy. Com `0`, ele para de conferir e ganha desempenho — mas **deixa de perceber código novo sozinho**.

O sintoma quando se esquece é cruel: o deploy roda sem erro nenhum, o `git log` mostra o commit certo, e o site continua com a versão antiga.

Outro item da mesma lista: `config:cache` junta todos os arquivos de `config/` num só. A partir daí, chamadas a `env()` **fora** de `config/` passam a devolver `null`. É o pega-ratão mais clássico do primeiro deploy Laravel. A regra: dentro de `config/`, use `env()`; em qualquer outro lugar, use `config()`.

**Quão comum no mercado:** cada framework tem sua lista de "coisas que só falham em produção". A de Laravel é praticamente esta. Roteiro de deploy escrito e versionado, em vez de memorizado, é o que separa um deploy repetível de uma sexta-feira ruim.

## 12. Fim de linha: o bug mais idiota e mais caro

Desenvolvimento no Windows, produção no Linux. O Windows termina linha com `\r\n`; o Linux, com `\n`.

Um script `bash` salvo com fim de linha do Windows falha assim:

```
./provision.sh: line 2: $'\r': command not found
```

A mensagem não diz absolutamente nada sobre fim de linha. Já custou tardes a muita gente.

A prevenção é um `.gitattributes`:

```
*.sh                  text eol=lf
infra/nginx/*         text eol=lf
infra/systemd/*       text eol=lf
```

O Git passa a garantir `\n` nesses arquivos **independentemente de quem os edita ou em que sistema**.

**Quão comum no mercado:** todo repositório com desenvolvimento em Windows e execução em Linux precisa disso. É uma das primeiras coisas que se adiciona a um projeto multiplataforma — geralmente depois de tropeçar uma vez.

## 13. Como conferir se está tudo de pé

```bash
curl -sS https://micasa-bionde.duckdns.org/up   # health check do Laravel
systemctl status micasa-queue                    # worker da fila
journalctl -u micasa-queue -n 50                 # log do worker
sudo tail -f /var/log/nginx/error.log            # erros do nginx
free -h                                          # RAM e swap
```

A tabela de diagnóstico ("sintoma → causa provável") está em [infra/README.md](../../infra/README.md), junto do runbook completo.

## 14. O que ficou de fora, e por quê

- **Envio automático do front.** Hoje o `npm run build` é local e o `dist/` sobe por `scp`. Automatizar no GitHub Actions é trabalho de uma issue própria, e o manual funciona.
- **Backup cifrado (issue #4).** O ADR-009 já definiu: cópia noturna, cifrada com `age`, enviada ao Backblaze B2, com a primeira restauração testada. Depende de uma conta B2.
- **`fail2ban`.** Custa RAM e o SSH aceita só chave, sem senha. Se o log mostrar volume de tentativas, entra.

## 15. Resumo do que este documento ensinou

| Assunto | Ideia que se leva |
|---|---|
| Deploy tardio | Adiar o primeiro deploy transforma deploy em migração |
| Nuvem gratuita | Leia o que o contrato **tira**, não só o que dá |
| IP efêmero | Reserve o endereço antes de DNS e certificado |
| Firewall em camadas | Console verde não significa porta aberta |
| Máquina pequena | Servidor de produção não compila |
| Origem única | Roteamento no cliente exige cumplicidade do servidor |
| Colisão de rotas | Escreva o contrato de caminhos antes que a produção o revele |
| Padrão seguro | Se o valor errado é silencioso, o padrão tem que ser o certo |
| SQLite WAL | Configuração versionada vale mais que comando manual |
| Fila e scheduler | Processo longo precisa de reinício para ver código novo |
| Opcache | O deploy que "funciona" e não muda nada |
| Fim de linha | `.gitattributes` desde o primeiro script |
