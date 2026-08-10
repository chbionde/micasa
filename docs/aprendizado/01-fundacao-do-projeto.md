# Aprendizado 01 — A fundação do projeto (o que foi feito, por quê, e como replicar)

> Este documento explica, para quem nunca viu nada disso, tudo que foi executado na primeira sessão de código do MiCasa: repositório, ambiente PHP, esqueletos do back e do front, ferramentas de qualidade e integração contínua.

---

## 1. O repositório Git e o "monorepo"

**O que foi feito:** conectamos a pasta local do projeto ao repositório do GitHub e decidimos que back-end e front-end moram no mesmo repositório, em pastas separadas (`api/` e `web/`). Isso se chama **monorepo**.

**Para que serve:** o Git guarda o histórico de cada mudança do projeto (quem mudou, o quê, quando), e o GitHub é a cópia na nuvem que também serve de vitrine e ferramenta de organização. Monorepo significa que uma funcionalidade que mexe no back e no front vive numa única mudança, revisável de uma vez.

**Como replicar:**
```bash
git init -b main                                  # cria o repositório local, ramo principal "main"
git remote add origin https://github.com/USUARIO/PROJETO.git   # aponta para o GitHub
git pull origin main                              # traz o que já existia lá
git add .  &&  git commit -m "mensagem"           # registra mudanças
git push -u origin main                           # envia para o GitHub
```

**No mercado:** Git é universal — 100% das vagas assumem que você sabe. Monorepo vs. multi-repo divide opiniões; empresas grandes (Google, Meta) usam monorepos gigantes, e para times pequenos é o padrão pragmático.

---

## 2. Laravel Herd — o ambiente PHP no Windows

**O que foi feito:** instalamos o **Laravel Herd**, que colocou na máquina o PHP 8.4 e o Composer, prontos e no PATH (ou seja, chamáveis de qualquer terminal).

**Para que serve:** PHP é a linguagem do back-end; **Composer** é o gerenciador de pacotes do PHP (equivalente ao npm do JavaScript) — ele baixa as bibliotecas que o projeto declara no arquivo `composer.json`. O Herd é um instalador que evita a configuração manual chata (baixar zip do PHP, editar PATH, extensões etc.).

**Como replicar:** baixar em https://herd.laravel.com, instalar, seguir o assistente. Verificar com `php --version` e `composer --version`.

**No mercado:** Herd é a recomendação oficial do Laravel para Windows/macOS. Alternativas que você verá em empresas: Docker (containers), WSL2 (Linux dentro do Windows), Laragon. O conceito importante é ter um ambiente reproduzível.

---

## 3. Scaffold do Laravel (`api/`)

**O que foi feito:** geramos o esqueleto oficial de um projeto Laravel novo dentro de `api/`.

```bash
composer create-project laravel/laravel api
```

**O que esse comando fez, por dentro:** baixou o "molde" de aplicação Laravel (pastas `app/`, `routes/`, `config/`, `database/`…), instalou as dependências no `vendor/` (que **não** vai para o Git — é reconstituível via `composer install`), gerou a chave de criptografia da aplicação (`APP_KEY` no arquivo `.env`) e criou um banco **SQLite** (um arquivo único em `database/database.sqlite` — sem servidor de banco separado) já com as tabelas básicas via **migrations**.

**Migrations, em uma frase:** são arquivos versionados que descrevem mudanças no banco ("crie a tabela users com estas colunas"), permitindo que qualquer máquina reconstrua o banco idêntico rodando `php artisan migrate`.

**No mercado:** Laravel é o framework PHP dominante — a imensa maioria das vagas PHP no Brasil cita Laravel. `create-project` é o jeito canônico de começar.

---

## 4. Scaffold do React + TypeScript (`web/`)

**O que foi feito:** geramos o esqueleto oficial de um front React com TypeScript usando o **Vite**.

```bash
npm create vite@latest web -- --template react-ts
cd web && npm install
```

**As peças, para quem nunca viu:**
- **React** — biblioteca que monta a interface a partir de *componentes* (funções que retornam HTML). Quando os dados mudam, o React re-renderiza só o necessário.
- **TypeScript** — JavaScript com tipos. O compilador (`tsc`) pega erros antes de rodar ("você passou texto onde era número").
- **Vite** — o servidor de desenvolvimento e empacotador: serve o app com recarga instantânea no dev e gera os arquivos otimizados de produção no `npm run build`.

**No mercado:** React + TypeScript + Vite é exatamente o trio mais listado em vagas de front no Brasil hoje. É a razão de existir deste projeto de aprendizado.

---

## 5. As ferramentas de qualidade do back-end

Instalamos três guardiões no `api/`, cada um pega um tipo diferente de problema:

| Ferramenta | O que pega | Comando |
|---|---|---|
| **Pint** | Formatação fora do padrão (espaços, imports…) | `vendor/bin/pint --test` |
| **Larastan (nível 6)** | Erros de tipo sem rodar o código ("esse método não existe nessa classe") | `vendor/bin/phpstan analyse` |
| **Pest** | Comportamento errado — roda o código de verdade e compara com o esperado | `php artisan test` |

**Pest, didaticamente:** é o framework de testes moderno do PHP (por baixo usa o PHPUnit). Um teste Pest se lê quase como frase:

```php
it('responde a raiz com sucesso', function () {
    $this->get('/')->assertStatus(200);   // finge um acesso HTTP e confere a resposta
});
```

**Uma decisão honesta que tomamos:** o Larastan analisa só o código de produção, não os testes — o PHPStan não entende o `$this` dentro dos closures do Pest (limitação conhecida do ecossistema). Os testes são validados pela própria execução.

**No mercado:** análise estática + formatador + testes é o tripé padrão de qualquer time PHP sério. Pint e Larastan aparecem nominalmente em vagas Laravel; Pest vem substituindo PHPUnit nos projetos novos.

---

## 6. As ferramentas de qualidade do front-end

| Ferramenta | O que pega | Comando |
|---|---|---|
| **oxlint** | Erros comuns de JS/React (veio no template do Vite) | `npm run lint` |
| **tsc** | Erros de tipo do TypeScript | embutido no `npm run build` |
| **Vitest + Testing Library** | Comportamento dos componentes | `npm run test` |

**Testing Library, a filosofia:** testar **como o usuário vê**, não como o código é escrito. Em vez de "ache o elemento com classe `.counter`", escrevemos "ache o **botão** cujo texto é *Count is 0*". Se um refactor não muda o que o usuário percebe, o teste continua passando — e de quebra o teste força acessibilidade (só encontra o botão se ele for um botão de verdade).

```tsx
const button = screen.getByRole('button', { name: /count is 0/i })
await user.click(button)                     // simula um clique como um humano faria
expect(button).toHaveTextContent('Count is 1')
```

**Um bug real que apareceu (e a lição):** o segundo teste falhou porque cada `render()` estava empilhando o componente no mesmo DOM falso sem limpar o anterior — havia dois botões iguais na "tela". A causa: o auto-cleanup da Testing Library depende de uma função global que nós desligamos ao escolher imports explícitos. A correção foi registrar a limpeza manualmente no `src/test/setup.ts`. Lição de mercado: entender **por que** a mágica funciona vale mais do que a mágica.

**No mercado:** Vitest + Testing Library é o padrão atual em projetos Vite (herdeiro direto do Jest + Testing Library, que você também verá muito).

---

## 7. CI — Integração Contínua no GitHub Actions

**O que foi feito:** dois arquivos em `.github/workflows/` que fazem o GitHub rodar **todas** as verificações acima, numa máquina deles, a cada push.

**Para que serve:** é a rede de segurança do projeto. Se um commit quebra um teste ou a formatação, o GitHub marca um ❌ vermelho — antes de qualquer deploy, antes de afetar alguém. "CI verde" vira o pré-requisito objetivo para aceitar qualquer mudança.

**Detalhe de monorepo:** cada workflow tem um **filtro de path** — mudou só `api/**`, roda só o pipeline PHP; mudou só `web/**`, roda só o de front. Economiza minutos de máquina e dá feedback mais rápido.

> ### Correção (2026-08-10) — o filtro de path foi removido, e a lição é outra
>
> O parágrafo acima descreve o que foi feito em agosto de 2026 e **estava errado como recomendação**. Fica aqui em vez de ser apagado, porque o erro ensina mais que o acerto.
>
> **O que acontece de verdade:** uma PR que toca só `docs/`, `infra/` ou `.github/` não casa com `api/**` nem com `web/**` — e então **não dispara workflow nenhum**. Ela não fica "verde": fica *sem check*. Foi o caso da PR #46, que só mexia em `infra/` e reportou `no checks reported`.
>
> Isso destrói a garantia principal do CI. Quando você tenta proteger a branch principal exigindo "só mergeia com check verde", o GitHub passa a esperar um check que **nunca vai chegar**, e a PR trava para sempre. O filtro torna impossível a regra que dá sentido ao CI.
>
> **A conta que ninguém tinha feito:** os workflows deste projeto levam de 25 a 43 segundos. O filtro economizava meio minuto por PR, numa conta gratuita onde repositório público não consome cota. Meio minuto em troca de não poder exigir CI verde é um péssimo negócio — e o problema ficou escondido por meses porque, até então, toda PR por acaso tocava `api/` ou `web/`.
>
> **A lição transferível:** otimização que economiza segundos e enfraquece uma garantia raramente compensa. E o modo de falha aqui é do tipo pior — silencioso: nada quebra, nada fica vermelho, o check simplesmente não existe, e quem olha rápido vê uma PR sem problema algum.
>
> Detalhe menor da mesma correção: os dois workflows chamavam o job de `quality`, o que produzia dois checks com nome idêntico e tornava ambígua qualquer regra que citasse "o check `quality`". Viraram `api` e `web`. **Nome de job é interface pública** — é por ele que a proteção de branch se refere ao check.
>
> Ver a issue #47.

**Como replicar (estrutura mínima de um workflow):**
```yaml
on:
  push:
    branches: [main]           # sem filtro de paths: ver a correção acima
  pull_request:
jobs:
  api:                         # nome do job = nome do check; use nomes distintos
    runs-on: ubuntu-latest     # máquina Linux do GitHub
    steps:
      - uses: actions/checkout@v5        # baixa o código
      - uses: shivammathur/setup-php@v2  # instala PHP
      - run: composer install
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse
      - run: php artisan test
```

**No mercado:** CI é onipresente — GitHub Actions é o mais comum em empresas que usam GitHub; você verá também GitLab CI e Jenkins. Saber ler e escrever um workflow é diferencial real de júnior.

---

## Resumo do estado atual

- Repositório: monorepo `api/` (Laravel 13) + `web/` (React 19 + TS + Vite), tudo no GitHub.
- Qualidade: Pint, Larastan 6 e Pest no back; oxlint, tsc e Vitest no front — tudo verde, local e no CI.
- Banco: SQLite local, migrado.
- Pendências da Fatia 0: autenticação (Sanctum SPA), layout base, e o bloco de infra (Oracle + backup) aguardando a criação das contas.
