# Quando o mesmo lockfile passa no PR e falha depois do merge

## O que aconteceu

O CI do frontend passou no PR #69 e falhou quando o mesmo commit chegou à `main`. O comando era
o mesmo nas duas execuções:

```bash
npm audit --audit-level=high
```

Não foi o HSTS nem uma mudança escondida no merge. O resultado do audit também depende do banco
de vulnerabilidades consultado naquele momento.

O lockfile trazia esta cadeia:

```text
vite@8.2.0 → postcss@8.5.25 → nanoid@3.3.17
```

O advisory [GHSA-2v37-7h3g-55p8](https://github.com/advisories/GHSA-2v37-7h3g-55p8) foi
atualizado em 13/08/2026 às 15:43 UTC para considerar vulnerável toda versão anterior à
`3.3.18`. O CI do PR havia terminado às 14:02 UTC. Assim, um lockfile que era aceito passou a
ser reprovado sem mudar um byte.

## O risco

A falha pode prender `customAlphabet` e `customRandom` num laço infinito quando recebem tamanho
zero controlado por um atacante, consumindo a thread e causando negação de serviço.

O MiCasa não importa `nanoid` diretamente; ela existe como ferramenta transitiva do build. Isso
reduz a exposição prática da aplicação, mas não justifica ignorar o alerta: havia uma versão
corrigida compatível e o gate de segurança existe justamente para impedir que uma dependência
conhecidamente vulnerável permaneça silenciosa.

## Por que não rodar `npm audit fix` sem revisar

O dry-run propôs atualizar `nanoid`, mas também registrar 58 pacotes opcionais de plataformas
que não tinham relação com o defeito. Aceitar essa mudança aumentaria o diff e a superfície de
regressão sem benefício.

A correção cirúrgica atualizou somente a entrada transitiva no `package-lock.json`:

```text
nanoid 3.3.17 → 3.3.18
```

Versão, URL e integridade vieram do registro oficial do npm. `package.json`, Vite e PostCSS
continuaram iguais. Uma instalação limpa com `npm ci` validou que o lockfile não foi editado de
forma inconsistente.

## Como reproduzir e verificar

```bash
cd web
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
nvm use 22

npm ci
npm ls nanoid --all
npm audit --audit-level=high
npm test -- --run
npm run lint
npm run build
```

A árvore deve mostrar `nanoid@3.3.18`, e o audit deve terminar com zero vulnerabilidades.

## Quão comum é no mercado

É comum um audit mudar de resultado sem mudança no código: novos advisories são publicados e os
intervalos afetados podem ser corrigidos depois. Por isso organizações executam auditoria tanto
em pull requests quanto em pushes da branch protegida e em rotinas agendadas. O ponto não é
garantir que um PR verde ficará verde para sempre; é detectar rapidamente quando o conhecimento
sobre uma dependência mudou.
