# infra/ — provisionamento e deploy

Runbook da VPS do MiCasa. Decisões que embasam este diretório: **ADR-003** (Oracle Always Free, sem PAYG) com a emenda de 2026-08-07 (região Vinhedo, forma AMD `E2.1.Micro`) e **ADR-020** (origem única e fronteira de caminhos).

## O que existe aqui

| Arquivo | Papel |
|---|---|
| `provision.sh` | Roda **uma vez** numa VPS nova. Deixa a máquina pronta, sem a aplicação. |
| `deploy.sh` | Roda **a cada** publicação. Atualiza código, dependências, banco e caches. |
| `backup.sh` | Roda **por cron, diariamente**. Cópia consistente do SQLite → gzip → `age` → Backblaze B2. |
| `restaurar.sh` | Roda **à mão, fora da VPS**. Decifra um backup e prova que o banco abre. |
| `limpar-banco.sh` | Roda **à mão, raramente**. Apaga TODOS os dados da aplicação. Destrutivo. |
| `aplicar-nginx.sh` | Roda **à mão, quando `infra/nginx/` mudar**. O deploy automático não toca no nginx. |
| `nginx/cabecalhos-seguranca.conf` | Cabeçalhos de segurança, num lugar só. Incluído pelas locations do site. |
| `micasa-pos-deploy.template` | Os três passos do deploy que exigem root. Instalado em `/usr/local/sbin/`. |
| `criar-conta-deploy.sh` | Roda **uma vez por VPS**. Cria a conta de deploy sem poder de root (#55). |
| `nginx/micasa.conf.template` | Site do nginx. O `provision.sh` substitui os `__PLACEHOLDER__`. |
| `systemd/micasa-queue.service` | Worker da fila. |
| `cron/micasa-scheduler` | Entrada única de cron do scheduler do Laravel. |
| `env.production.example` | Modelo do `api/.env` de produção. |

## Pré-requisitos na nuvem

Feitos no console da Oracle, fora deste script:

- Instância `VM.Standard.E2.1.Micro`, Ubuntu 24.04, em sub-rede **pública**
- **IP público reservado** (não efêmero) — sem isso o endereço muda quando a instância é parada e religada, e DNS e certificado quebram junto
- Lista de Segurança da VCN com entrada TCP em **80** e **443**, origem `0.0.0.0/0`
- DNS apontando para o IP reservado

> Liberar as portas na Lista de Segurança **não basta**. As imagens Ubuntu da Oracle trazem regras `iptables` que descartam tudo além de SSH; quem resolve isso é o `provision.sh`.

## Primeira vez

```bash
# 1. Clone o repositório no lugar definitivo
sudo mkdir -p /var/www/micasa
sudo chown "$USER:www-data" /var/www/micasa
git clone https://github.com/chbionde/micasa.git /var/www/micasa
cd /var/www/micasa

# 2. Provisione (fuso, swap, iptables, PHP, nginx, fila, scheduler, TLS)
sudo DOMINIO=micasa-bionde.duckdns.org EMAIL_TLS=voce@exemplo.com ./infra/provision.sh

# 3. Configure a aplicação
cp infra/env.production.example api/.env
nano api/.env                      # confira APP_URL, FRONTEND_URL, e-mail

# 4. Instale as dependências e gere a chave
#    Nesta ordem: o key:generate roda pelo artisan, que precisa do vendor/.
cd api
composer install --no-dev --optimize-autoloader
php artisan key:generate
cd ..

# 5. Publique
./infra/deploy.sh
sudo systemctl start micasa-queue
```

O `deploy.sh` repete o `composer install` do passo 4 — na segunda vez ele não baixa nada e custa segundos. A repetição é preferível a um deploy que só funciona depois de alguém ter rodado o passo certo à mão.

## Ensaio fora da VPS

`PULAR_AJUSTES_DE_HOST=1` desliga as cinco seções que só existem por causa da máquina real — fuso, swap, `iptables`, `fail2ban` e `certbot` — e deixa rodar o resto num WSL ou numa VM descartável. Serve para descobrir erro de digitação e de caminho antes de tocar no servidor.

```bash
sudo PULAR_AJUSTES_DE_HOST=1 DOMINIO=micasa.localhost ./infra/provision.sh
```

`EMAIL_TLS` é dispensado nesse modo: ele só alimenta o certbot. Exige systemd (`/etc/wsl.conf` com `[boot] systemd=true`, depois `wsl --shutdown`).

**Nunca use esta variável na VPS.** O que sai dela não é um servidor de produção: sem swap, o FPM cai no primeiro pico; sem a regra de `iptables`, a porta 80 dá timeout; sem certificado, não há HTTPS.

O que o ensaio **cobre**: nginx e o roteamento de origem única do ADR-020, permissões, o WAL criando `-wal`/`-shm`, `composer install`, `migrate`, `config:cache`, a unit da fila e o `deploy.sh` inteiro. É justamente o que falha em silêncio.

O que ele **não cobre**:

| Seção | Por quê |
|---|---|
| `iptables` | As regras DROP vêm da imagem Ubuntu da Oracle. Fora dela não há o que corrigir — o "funcionou" é vazio |
| `fail2ban` | A jail insere regra no `iptables` real e só tem o que banir numa máquina exposta à internet. No WSL o serviço subiria falhando |
| Swap | O WSL2 gerencia swap na própria VM; `swapon` devolve `Operation not permitted` |
| `certbot` | O desafio HTTP-01 precisa alcançar a porta 80 pelo nome do domínio; o WSL está atrás de NAT |
| Pressão de 1 GB | A máquina de ensaio tem RAM de sobra. O motivo de `ondemand`, do swap e de não buildar o front no servidor não aparece |

## Publicações seguintes — automáticas

**Merge na `main` publica sozinho.** O workflow `.github/workflows/deploy.yml` builda o front no runner do GitHub, envia `web/dist` por `rsync` e roda o `deploy.sh` na VPS. Para publicar sem um merge, use o botão **Run workflow** na aba Actions.

O `deploy.sh` **não** roda `npm run build`: Vite mais `tsc` não cabem com folga em 1 GB de RAM e 1/8 de OCPU. O build acontece no runner, que tem memória de sobra — foi o que permitiu automatizar sem tocar nessa restrição.

O deploy publica sempre da `main`, e o script recusa rodar em outra branch. Isso não é preciosismo: a VPS ficou na branch da PR depois do primeiro provisionamento, e nesse estado o `git pull` publicaria código que não é o da `main`, em silêncio.

### Publicar à mão, quando precisar

```bash
# front (na sua máquina)
cd web && npm run build
scp -i ~/.ssh/id_ed25519 -r dist/* ubuntu@SEU_IP:/var/www/micasa/web/dist/

# back (na VPS)
cd /var/www/micasa && ./infra/deploy.sh
```

Não é preciso recarregar o nginx para o front: são arquivos estáticos, servidos direto do disco.

### ⚠️ Mudança em `infra/nginx/` NÃO sai no deploy

O deploy automático builda o front, faz `rsync` do `dist` e roda o `deploy.sh`. Ele **não encosta no nginx**. Enquanto ninguém aplicar, o repositório diz uma coisa e a produção faz outra — que é exatamente como a #48 nasceu.

Para aplicar, **na VPS**:

```bash
cd /var/www/micasa && git pull --ff-only && ./infra/aplicar-nginx.sh
```

O script instala o snippet dos cabeçalhos, regenera o site a partir do template, **restaura o TLS** (regenerar apaga as linhas que o certbot escreveu — sem este passo o site volta em HTTP puro), testa e recarrega. Se o teste falhar, ele repõe a configuração anterior e não recarrega nada.

No fim ele confere os cabeçalhos por HTTP e diz quais chegaram.

### As credenciais do deploy automático

A Action usa uma chave SSH **dedicada** (`micasa-deploy`), sem passphrase, separada da chave pessoal. A privada vive só nos *Secrets* do repositório; a pública está no `authorized_keys` da VPS com `no-port-forwarding,no-agent-forwarding,no-X11-forwarding`.

Para trocar a chave (se vazar, ou por rotação):

```bash
ssh-keygen -t ed25519 -N "" -C "micasa-deploy (github actions)" -f /tmp/micasa-deploy
gh secret set SSH_PRIVATE_KEY < /tmp/micasa-deploy
# na VPS: substitua a linha correspondente em ~/.ssh/authorized_keys pela nova pública
rm -f /tmp/micasa-deploy*
```

**O que essa chave pode fazer:** ela entra como `micasa-deploy`, uma conta separada da sua, sem senha e com `sudo` autorizado a **um** comando: `/usr/local/sbin/micasa-pos-deploy`.

Seja honesto sobre o ganho: isso reduz o pior caso de *"root na máquina"* para *"controle da aplicação"*. **Não elimina o segundo** — a chave abre um shell e o `deploy.sh` faz `git pull`. Consertar isso seria outra arquitetura de deploy (artefato assinado, servidor puxando em vez de recebendo), e não se justifica neste projeto hoje.

### Por que um script, e não três comandos no sudoers

A regra óbvia seria listar os três comandos do `deploy.sh`. O sudoers casa a linha de comando **literalmente**, e o `deploy.sh` chamava `chmod -R g+w storage bootstrap/cache database` com caminhos **relativos** — uma regra que autorize isso autoriza o mesmo `chmod` em qualquer diretório onde exista uma pasta `storage`, bastando um `cd` antes. A regra pareceria restrita e não seria.

Por isso a autorização é para o script, **sem argumento nenhum**. Os caminhos são absolutos e ficam dentro dele, que é `root:root 0755` — a conta de deploy executa e não escreve. Se escrevesse, reescreveria o script e teria root de volta.

### Migrando uma VPS que já existe (#55)

**Antes de começar, entenda o desenho.** Hoje a Action entra na VPS como `ubuntu`, que tem sudo
irrestrito. Vamos criar uma conta separada — `micasa-deploy` — que só consegue rodar um único
comando como root. Sua conta `ubuntu` **não muda**: ela continua sendo como você administra a
máquina, e é o caminho de volta se algo der errado.

A ordem importa. **Nada do acesso antigo é removido antes de o novo funcionar.** São 7 passos;
faça um por vez e confira a saída de cada um antes de seguir.

---

#### Passo 1 — Atualizar o código na VPS

**O que faz:** traz os arquivos novos (o script da conta de deploy e o que roda como root).
**Onde:** na VPS, pelo SSH, com o seu usuário `ubuntu`.

```bash
cd /var/www/micasa && git status --porcelain && git pull --ff-only
```

**Saída esperada:** o `git status --porcelain` **não imprime nada**, e depois vem
`Updating ...` ou `Already up to date.`

**Se divergir:** se o `git status` listar arquivos (você abriu o `provision.sh` no `nano` numa
tentativa anterior — pode ter salvado sem querer), desfaça antes de continuar:

```bash
git checkout -- infra/provision.sh && git status --porcelain
```

---

#### Passo 2 — Criar a conta de deploy

**O que faz:** cria o usuário `micasa-deploy`, instala `/usr/local/sbin/micasa-pos-deploy`
(os três comandos do deploy que exigem root) e escreve a regra de sudo que autoriza **só** esse
script.
**Onde:** na VPS.

> Este passo **não** é o `provision.sh`. Aquele monta a máquina inteira — pacotes, PHP-FPM,
> swap, iptables, fail2ban, certbot — e seria desproporcional para criar um usuário. O
> `provision.sh` chama este mesmo script, então máquina nova e máquina antiga passam pelo mesmo
> código.

```bash
sudo ./infra/criar-conta-deploy.sh
```

**Saída esperada:** cinco vistos e o `Pronto`.

```
==> Conferindo o que a conta de deploy pode fazer com sudo
  ✓ a conta pode rodar /usr/local/sbin/micasa-pos-deploy
  ✓ sem sudo irrestrito
  ✓ shell: /bin/bash
  ✓ no grupo www-data
  ✓ /home/micasa-deploy/.ssh existe, pronto para receber a chave

==> Pronto.
```

Rodar de novo é seguro: ele diz `A conta micasa-deploy já existe` e segue conferindo.

**Se divergir:**

- `A regra saiu inválida. NADA foi instalado` — seu sudo continua intacto, porque o script
  valida antes de instalar. Me mande a saída.
- `A conta não recebeu a autorização esperada` — o script imprime **a evidência logo acima do
  erro**: o conteúdo do arquivo de regra, as permissões dele e o que o sudo enxerga para a
  conta. Copie esse bloco inteiro e me mande. Você não precisa ir olhar nada à mão.

---

#### Passo 3 — Gerar uma chave nova para o deploy

**O que faz:** cria um par de chaves só para a Action. Aproveitamos para rotacionar: a chave
antiga deixa de valer no fim da migração.
**Onde:** na **sua máquina** (WSL), não na VPS.

```bash
ssh-keygen -t ed25519 -N "" -C "micasa-deploy (github actions)" -f /tmp/micasa-deploy
cat /tmp/micasa-deploy.pub
```

**Saída esperada:** uma linha começando com `ssh-ed25519 AAAA...` e terminando em
`micasa-deploy (github actions)`. **Copie essa linha inteira** — ela é a chave *pública*.

⚠️ O arquivo `/tmp/micasa-deploy` (sem `.pub`) é a chave **privada**. Não cole o conteúdo dele
em lugar nenhum — nem aqui na conversa. Ele vai direto para o secret do GitHub no passo 5.

---

#### Passo 4 — Autorizar essa chave na conta nova

**O que faz:** permite que quem tiver a chave privada entre como `micasa-deploy`.
**Onde:** na VPS.

Cole o comando abaixo trocando `CONTEUDO-DA-PUBLICA` pela linha que você copiou no passo 3
(ela começa com `ssh-ed25519`):

```bash
echo 'CONTEUDO-DA-PUBLICA' | sudo tee -a /home/micasa-deploy/.ssh/authorized_keys
sudo chown micasa-deploy:micasa-deploy /home/micasa-deploy/.ssh/authorized_keys
sudo chmod 600 /home/micasa-deploy/.ssh/authorized_keys
```

**Saída esperada:** o `tee` repete a linha na tela; os outros dois não imprimem nada.

**Confira antes de seguir:**

```bash
sudo ls -l /home/micasa-deploy/.ssh/authorized_keys
```

Tem de mostrar `-rw------- 1 micasa-deploy micasa-deploy`.

---

#### Passo 5 — Dar o diretório da aplicação à conta nova

**O que faz:** a conta de deploy precisa escrever no repositório (`git pull`, `composer
install`, caches). Hoje o dono é `ubuntu`.
**Onde:** na VPS.

```bash
sudo chown -R micasa-deploy:www-data /var/www/micasa
```

**Saída esperada:** nada. **Confira:**

```bash
ls -ld /var/www/micasa
```

Tem de mostrar `micasa-deploy www-data`.

> Sua conta `ubuntu` continua no grupo `www-data`, então você continua conseguindo ler e editar
> ali. Para comandos que escrevem, use `sudo -u micasa-deploy`.

---

#### Passo 6 — Apontar o GitHub para a conta nova

**O que faz:** troca a chave e o usuário que a Action usa.
**Onde:** na **sua máquina** (WSL), no diretório do repositório.

```bash
gh secret set SSH_PRIVATE_KEY < /tmp/micasa-deploy
gh secret set SSH_USER --body micasa-deploy
rm -f /tmp/micasa-deploy /tmp/micasa-deploy.pub
```

**Saída esperada:** `✓ Set Actions secret SSH_PRIVATE_KEY` e o mesmo para `SSH_USER`.

---

#### Passo 7 — Testar o deploy, e só então fechar a porta antiga

**O que faz:** confirma que a conta nova publica de verdade, antes de você perder a antiga.
**Onde:** no GitHub, aba **Actions** → workflow **Deploy** → botão **Run workflow**.

**Saída esperada:** todos os passos verdes, e no log do passo *Publicar o back* **não** aparece
a linha:

```
[aviso] A conta de deploy ainda usa sudo irrestrito (#55 em aberto).
```

Se esse aviso aparecer, o passo 2 não foi aplicado — pare e volte nele.

**Só depois do deploy verde**, remova a chave de deploy antiga da conta `ubuntu`.

Não faça isso no `nano`. Editar `authorized_keys` à mão é como se perde acesso a um servidor —
uma linha errada apagada e você não entra mais. O caminho abaixo tem cópia de segurança,
conferência antes de aplicar, e um teste que você faz **sem fechar a sessão atual**.

**7a. Ver o que existe hoje**, com número de linha:

```bash
cat -n ~/.ssh/authorized_keys | cut -c1-60
```

**Saída esperada:** duas linhas. Uma termina em `micasa-oracle` — **essa é a sua chave pessoal,
ela FICA**. A outra termina em `micasa-deploy (github actions)` — essa é a de deploy antiga, e é
a que sai.

> Como saber que `micasa-oracle` é a sua: é o comentário da sua chave local, que você confere
> com `awk '{print $3, $4}' ~/.ssh/id_ed25519.pub` na sua máquina.

**7b. Guardar uma cópia e montar a versão nova:**

```bash
cp ~/.ssh/authorized_keys ~/.ssh/authorized_keys.bak
grep -v 'micasa-deploy' ~/.ssh/authorized_keys > /tmp/ak.novo
cat -n /tmp/ak.novo | cut -c1-60
```

**Saída esperada:** **uma** linha, terminando em `micasa-oracle`.

**Se divergir:** se vier vazio ou sem a linha `micasa-oracle`, **pare** — não aplique. Nada foi
alterado ainda; o `authorized_keys` continua intacto.

**7c. Aplicar:**

```bash
cat /tmp/ak.novo > ~/.ssh/authorized_keys && rm -f /tmp/ak.novo
cat -n ~/.ssh/authorized_keys | cut -c1-60
```

> `cat >` em vez de `mv`, de propósito: preserva dono e permissão do arquivo. O `sshd` recusa
> `authorized_keys` com permissão frouxa, e o sintoma seria você não conseguir mais entrar.

**7d. Testar SEM fechar esta sessão.** Abra um **segundo terminal** na sua máquina e entre:

```bash
ssh ubuntu@167.126.4.86
```

**Se entrar:** está feito. Apague a cópia de segurança pela sessão nova:

```bash
rm -f ~/.ssh/authorized_keys.bak
```

**Se NÃO entrar:** não feche o primeiro terminal. Por ele, desfaça:

```bash
cp ~/.ssh/authorized_keys.bak ~/.ssh/authorized_keys
```

E me mande a mensagem de erro do segundo terminal.

---

### Terminou a migração. E agora?

Nada mais neste documento. A partir daqui:

- O deploy entra como `micasa-deploy`, que só pode rodar `/usr/local/sbin/micasa-pos-deploy`
  como root.
- Sua conta `ubuntu` continua com acesso administrativo pela chave `micasa-oracle`.
- A chave de deploy antiga não vale mais em lugar nenhum.

## Backup

Diário às **06:00 UTC** (03:00 em São Paulo), por `/etc/cron.d/micasa-backup`. Implementa o ADR-009 e sua emenda de 2026-08-10.

```bash
./infra/backup.sh --testar            # valida credenciais e upload, sem tocar no banco
./infra/backup.sh                     # o que o cron roda
./infra/backup.sh --local /tmp        # grava cifrado no disco, sem subir nada
journalctl -t micasa-backup --since today
```

**A chave privada do `age` não existe nesta máquina, e isso é o desenho, não um esquecimento.** A VPS só tem a pública, que cifra e não decifra. Quem invadir o servidor não abre backup nenhum — nem os antigos. O `backup.sh` aborta se achar `AGE-SECRET-KEY` no `.env`.

Consequência prática: **restaurar não se faz aqui.** Baixe o arquivo (console da Backblaze, ou a chave Read Only) e rode na sua máquina:

```bash
./infra/restaurar.sh micasa-AAAAMMDDTHHMMSSZ.sqlite.gz.age ~/sua-chave-privada.key
```

Ele decifra, confere `integrity_check`, conta as linhas de cada tabela e mostra **a data do registro mais recente**. Essa data é o que importa: um backup íntegro de três meses atrás passa em todas as outras verificações e ainda assim significa que o cron parou em silêncio há três meses.

**A retenção de 30 dias é Lifecycle Rule do bucket, não do script.** A chave é Write Only e não tem permissão para apagar — de propósito: nem um invasor com a credencial da máquina apaga o histórico.

**O vigia é quem avisa, não o log.** `BACKUP_PING_URL` é chamada quando o backup termina bem; um observador externo alarma quando a chamada não chega. Se estiver vazia, uma falha passa em silêncio — inclusive a falha de o cron nunca ter rodado, que nenhum log dentro da VPS registraria.

## Limpando o banco

Existe para quando as contas em produção são testes que perderam a validade. **É destrutivo e
não tem desfazer.**

```bash
./infra/limpar-banco.sh --conferir    # só mostra o que existe hoje. NÃO apaga
./infra/limpar-banco.sh               # apaga, depois de backup e confirmação
```

Sempre rode o `--conferir` primeiro. Se a contagem não bater com o que você espera, você está
na máquina errada ou o sistema teve uso que você não sabia — nos dois casos, pare.

O que ele faz, nesta ordem, e para de vez em qualquer tropeço:

1. Conta o que existe e mostra na tela.
2. **Tira um backup cifrado** em `~/backups-antes-de-limpar/`, usando o mesmo `backup.sh --local`
   do backup diário. Se o backup não sair, ou sair pequeno demais para ser real, **nada é
   apagado**.
3. Pede a frase `APAGAR TUDO` por extenso. Um "s" sai por reflexo; uma frase, não.
4. `php artisan migrate:fresh --force` — derruba e recria as tabelas.
5. Ajusta as permissões do grupo `www-data`, senão o site volta sem escrita.
6. Reconta (tem de dar zero) e confere se `/up` responde 200.

Depois disso **todas as contas deixaram de existir**, inclusive a sua. Cadastre-se de novo — e a
senha terá de passar na política atual: mínimo de 10 caracteres e ausente das listas de
vazamento conhecidas.

O backup do estado anterior só se lê com a chave privada do `age`, que não está na VPS. Para
voltar atrás, é o mesmo caminho de qualquer restauração: `./infra/restaurar.sh`.

## Cabeçalhos de segurança

Moram em `infra/nginx/cabecalhos-seguranca.conf`, num arquivo só, porque `add_header` no nginx **não é aditivo**: uma `location` com add_header próprio descarta todos os herdados do `server`. São quatro cabeçalhos que precisariam ser repetidos em três locations — doze linhas para manter em sincronia à mão, e a primeira que envelhecesse sozinha viraria um buraco silencioso.

Conferir de fora, a qualquer momento:

```bash
curl -sSI https://micasa-bionde.duckdns.org/ | grep -iE "x-content-type|x-frame|referrer|content-security"
```

### Sobre a CSP bloqueante

A política completa limita scripts, estilos, imagens, fontes e conexões à própria origem. Também bloqueia plugins, alteração de URL por `<base>`, enquadramento por outros sites e envio de formulários para fora do MiCasa.

Ela não foi preparada direto em bloqueio. Primeiro ficou em `Content-Security-Policy-Report-Only`; o desenvolvedor navegou pelas telas autenticadas em janela anônima, sem violações, em 12/08/2026. A configuração versionada então promoveu a mesma política para `Content-Security-Policy`. Produção só muda depois de executar `infra/aplicar-nginx.sh` e a conferência final do script passar.

Se uma dependência futura precisar de origem externa ou conteúdo inline, não adicione permissões por tentativa. Recoloque primeiro a política candidata em `Report-Only`, navegue pelo fluxo afetado e libere apenas a fonte medida.

## Verificando

```bash
curl -sS https://micasa-bionde.duckdns.org/up      # health check do Laravel
systemctl status micasa-queue                      # worker da fila
journalctl -u micasa-queue -n 50                   # log do worker
sudo tail -f /var/log/nginx/error.log              # erros do nginx
free -h                                            # RAM e swap
sudo fail2ban-client status sshd                   # IPs banidos no SSH
```

**O fail2ban só está funcionando se houver banimento.** A máquina recebe centenas de tentativas de SSH por dia, então `Currently banned: 0` logo após subir é sinal de que a jail não está lendo o journal — não de que a internet ficou educada. `sudo fail2ban-client get sshd bantime` deve devolver `3600`; se devolver `600`, o `jail.d/micasa.local` não foi lido.

## Quando algo quebra

| Sintoma | Causa provável |
|---|---|
| Porta 80 dá timeout, console da Oracle "certo" | `iptables` da imagem Ubuntu. Rode o `provision.sh` de novo. |
| `502 Bad Gateway` | PHP-FPM caiu. `systemctl status php8.4-fpm` e `free -h` — se a swap estiver cheia, foi memória. |
| `attempt to write a readonly database` | O `-wal` e o `-shm` são criados pelo PHP-FPM como `www-data`, e o setgid do diretório só herda o grupo, não o dono. Rode `id -nG` — se o usuário de deploy não estiver em `www-data`, ele só tem leitura neles. O `provision.sh` o adiciona ao grupo, mas **grupo novo só vale em sessão nova**: saia e entre no SSH. O `database.sqlite` estar gravável não descarta esse caso. |
| Deploy passa e o código não muda | Falta `systemctl reload php8.4-fpm`. Com `opcache.validate_timestamps=0` o PHP não percebe arquivo novo. |
| `chmod: ... Operation not permitted` no deploy | Arquivo criado pelo `www-data` (o log do dia, via cron do scheduler). `chmod` exige ser dono — por isso o `deploy.sh` usa `sudo`. Se você removeu o `sudo`, o deploy morre aí e o FPM não recarrega. |
| `key:generate` diz que falta `vendor/autoload.php` | Ordem invertida na primeira instalação: o `composer install` vem antes. Ver "Primeira vez", passo 4. |
| "Esqueci minha senha" não gera link nenhum no log | `MAIL_MAILER=log` grava em nível DEBUG e `LOG_LEVEL=warning` descarta. Nada quebrou. Ver a receita em `infra/env.production.example`. |
| `env()` devolve `null` em produção | `config:cache` já rodou. Fora de `config/`, use `config()`, nunca `env()`. |
| Login não persiste | `SESSION_DOMAIN` preenchido. Em origem única ele fica vazio. |
| Certbot falha | DNS ainda não propagou. `dig +short SEU_DOMINIO` e tente de novo. |
| HTTPS parou depois de rodar o `provision.sh` de novo | O script regenera o conf do nginx a partir do template, que só tem `listen 80`, e o certbot havia escrito o SSL nesse mesmo arquivo. Corrigido: quando o certificado já existe, o script roda `certbot install` para reescrever a configuração. Se ainda acontecer: `sudo certbot install --nginx --cert-name SEU_DOMINIO --redirect`. |
| Rota nova do servidor devolve HTML da SPA | Ela não está na lista de caminhos do nginx. Ver ADR-020. |
| Seu IP levou ban no SSH | `sudo fail2ban-client set sshd unbanip SEU_IP`. O ban expira sozinho em 1 h. Se persistir depois disso, um `netfilter-persistent save` gravou a regra em disco com o fail2ban no ar — a seção 3 do `provision.sh` existe para impedir isso. |
| `fail2ban-client status sshd` diz `Currently banned: 0` | A jail não está lendo o journal. Confira `sudo fail2ban-client get sshd bantime` (tem de ser `3600`) e `journalctl -u fail2ban -n 30`. Numa máquina exposta, zero banimento é defeito, não sorte. |
| Procurando chain `f2b-sshd` no `iptables` e não achando | Esse é o nome da ação `iptables-multiport`; o Ubuntu 24.04 usa `banaction = nftables`. Olhe com `sudo nft list table inet f2b-table`. O `iptables` aqui é `iptables-nft` e **não mostra** tabelas fora das que ele mapeia. |
| Backup "não roda" e não há erro | O cron manda tudo para o journal: `journalctl -t micasa-backup --since today`. Se não houver linha nenhuma, o cron não disparou — confira `/etc/cron.d/micasa-backup` e `systemctl status cron`. |
| `b2_authorize_account falhou` | Credencial errada, revogada ou expirada. Rode `./infra/backup.sh --testar`: ele imprime as capacidades reais da chave. Sem `writeFiles`, ela não sobe nada. |
| `age` recusa decifrar na restauração | Chave privada errada. A pública (`age1...`) **não** decifra — é matemática, não permissão. Confira que o arquivo tem `AGE-SECRET-KEY`. |
