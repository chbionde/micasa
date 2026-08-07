# MiCasa — Registro de Decisões de Arquitetura (ADRs)

> Fase 0 concluída em 2026-08-05. Cada ADR segue: contexto → opções → decisão → consequências.
> Decisões **adiadas** estão na seção final, cada uma com o gatilho que obriga a revisitá-la.

---

## ADR-001 — Arquitetura: API Laravel + SPA React

**Contexto:** objetivo duplo do projeto (produto + empregabilidade em React/TS). Pesquisa de mercado (ago/2026, Indeed/ProgramaThor/getVagas) mostra que vagas React no Brasil pedem majoritariamente SPA + consumo de API REST; Inertia.js é nicho.
**Opções:** Inertia.js (entrega mais rápida, sem CORS/Sanctum) vs. API + SPA (mais fiação, é o que as vagas pedem).
**Decisão:** API Laravel + SPA React (Vite). Autenticação via Sanctum em modo SPA (cookie + CSRF).
**Consequências:** ~2–3 semanas extras de fiação inicial no ritmo de 5h/semana. O bot Telegram e um futuro PWA se beneficiam da API existir. A fiação (Sanctum, CORS, interceptors) é conteúdo de entrevista — faz parte do aprendizado, não é desperdício.

## ADR-002 — Repositório: monorepo

**Contexto:** dev solo, trabalho em fatias verticais que atravessam back e front.
**Opções:** monorepo (`api/` + `web/`) vs. dois repositórios.
**Decisão:** monorepo.
**Consequências:** um PR por fatia, CI único com filtros de path, tipos TS do contrato da API na mesma árvore. Dois repos só faria sentido com dois times.

## ADR-003 — Hospedagem: Oracle Always Free (sem PAYG), plano B Hostinger

**Contexto:** orçamento zero. Oracle Always Free em 2026: ARM 2 OCPU/12 GB (reduzido em jun/2026). Riscos: capacidade escassa na região de São Paulo; recuperação de instância ociosa (<10% CPU/rede por 7 dias). Preocupação explícita do dev com cobrança em dólar → upgrade PAYG descartado (PAYG não tem trava de gasto, fatura em USD).
**Opções:** Oracle free pura / Oracle PAYG / VPS paga em BRL (Hostinger KVM 1, ~R$ 28/mês, DC São Paulo).
**Decisão:** Oracle Always Free **sem** upgrade PAYG. Risco de cobrança: zero (faturamento inexiste na conta free).
**Consequências:** a VM é descartável; os dados não. Mitigações obrigatórias na Fatia 0: backup off-site cifrado (ADR-009) + script de provisionamento documentado. **Plano B registrado:** se a fricção da Oracle (capacidade/recuperação) consumir mais de 2 semanas de trabalho, migrar para Hostinger KVM 1 (cobrança em reais). Código agnóstico de banco e de host para a migração custar uma tarde.

## ADR-004 — Autenticação: e-mail + senha; Google adiado

**Contexto:** Fatia 0 exige auth. Ideia inicial do dev era "login com Gmail". Esclarecido: login Google e sincronização com Google Agenda são decisões independentes (scopes separados, autorização incremental possível depois).
**Opções:** e-mail+senha (Sanctum SPA) / só Google (Socialite) / ambos.
**Decisão:** e-mail + senha na v1. E-mail transacional (reset de senha) via tier gratuito (ex.: Brevo).
**Consequências:** é o fluxo mais cobrado em vaga (Sanctum, CSRF, sessão SPA). Sem dependência do Google Cloud Console na fatia mais burocrática. Google entra depois via Socialite — ver decisões adiadas.

## ADR-005 — i18n: não na v1

**Contexto:** custo assimétrico — backend é ~zero (pacote de idioma pt-BR do Laravel, necessário de qualquer forma); frontend é fricção diária (`t('chave')` em todo componente) durante a fase de aprendizado de React.
**Decisão:** textos do front direto em pt-BR; backend com pacote de idioma pt-BR (que já é o caminho i18n-correto do Laravel). Retrofit futuro é trabalho mecânico de poucos dias, sem risco arquitetural.
**Gatilho de revisão:** usuário real que não lê português, ou uso do projeto como portfólio internacional.

## ADR-006 — Método de aprendizado no front: híbrido manual→lib

**Contexto:** objetivo de empregabilidade + literatura de aprendizagem (dificuldade desejável pertinente, carga cognitiva, prática deliberada). Usar lib sem ter sentido o problema que ela resolve produz conhecimento raso que falha em entrevista.
**Decisão:**
| Conceito | Abordagem |
|---|---|
| Busca de dados | Manual primeiro (`useEffect`+`fetch`, loading/erro), migra para TanStack Query na fatia seguinte |
| Formulários | Manual primeiro (controlados + validação à mão, 1 formulário), depois RHF + Zod para sempre |
| Roteamento | React Router direto |
| Estado global | `useState`/`useContext` nativos; nenhuma lib até doer |

**Consequências:** ~1 sessão (5h) extra por conceito, concentrada nas fatias 2–3. Versões manuais viram commits de exercício, não código final. A migração manual→lib é material de estudo do Tutor React.

### Emenda (2026-08-07) — RHF + Zod antecipado da Fatia 3 para a Fatia 2

**Contexto:** o ADR previa formulários manuais primeiro e RHF + Zod "na fatia seguinte", com o plano marcando a adoção na Fatia 3. Até o fim da Fatia 1.5 foram escritos **oito formulários controlados à mão** (login, registro, três seções da conta, renomear casa, esqueci senha, redefinir senha) — o objetivo de "sentir a fiação antes da abstração" está cumprido com folga. O formulário de item de lista (Fatia 2) tem seis campos opcionais, que é onde o manual passa a custar mais do que ensina.
**Decisão (dev, 2026-08-07):** adotar **React Hook Form + Zod na Fatia 2**, a partir da issue #36 (front das listas).
**Consequências analisadas:**
- **Cronograma:** Fatia 2 ganha ~1 sessão (5h) para aprender RHF + Zod; Fatia 3 perde a mesma sessão, já que chegará com a lib conhecida. **Efeito líquido no cronograma: zero.**
- **Aprendizado:** nenhuma perda — o exercício manual já foi feito oito vezes; o que muda é o momento de colher o ganho.
- **Risco:** baixo. A Fatia 2 já não tem outro conceito novo grande (TanStack Query entrou na Fatia 1).
- **O que fica desatualizado:** a coluna "conteúdo de aprendizado" das fatias 2 e 3 em `plano-fatias.md`, corrigida junto.
- **O que NÃO muda:** o método híbrido do ADR-006 continua valendo para os próximos conceitos (ex.: estado global só quando doer).

## ADR-007 — Multi-casa: cadastro cria casa, convite por link, pivô com papel

**Contexto:** suporte a várias casas decidido desde o início ("adicionar depois é caro"). Faltava o modelo de entrada.
**Decisão:**
1. Cadastro público cria casa nova; quem cadastra vira admin dela. Não existe conta órfã.
2. Membro entra por **link de convite** com token (expira, revogável), compartilhado por WhatsApp/Telegram. Sem convite por e-mail.
3. Vínculo usuário↔casa é **tabela pivô `household_user` com papel por casa** (admin/membro). Uma pessoa pode pertencer a várias casas; UI tem seletor de casa ativa.

**Consequências:** um join a mais em tudo; policies sempre por casa. Cobre cenários reais (membro de duas casas, faxineira em duas casas).

### Emenda (2026-08-06) — pessoa sem casa passa a ser estado válido

**Contexto:** teste de uso real mostrou que quem cria a casa sozinho fica preso nela — a regra do último administrador impedia sair, e não havia como apagar a casa.
**Decisão:**
1. O cadastro **continua** criando uma casa (nada muda no registro).
2. **Casa nunca fica vazia:** se quem sai é o único membro, a casa é apagada junto.
3. **Pessoa sem casa é estado válido** — ex.: alguém que saiu e aguarda convite. A UI trata `casa_ativa` nula.
4. A regra do último admin **permanece** quando há outros membros: não dá para sair deixando a casa sem quem administra.

**Consequências:** substitui a frase original "Não existe conta órfã". Toda tela e todo endpoint precisam tolerar usuário sem casa ativa.

## ADR-008 — Tempo: UTC no banco, timezone por casa, vencimento como data civil

**Contexto:** "hora do servidor" é armadilha — o servidor é descartável (ADR-003). Multi-casa pode cruzar fusos brasileiros (SP UTC-3, Manaus UTC-4).
**Decisão:**
1. Timestamps em UTC no banco; API entrega ISO-8601 UTC; front exibe no fuso da casa.
2. Campo `timezone` por casa, padrão `America/Sao_Paulo`.
3. **Vencimentos e afins são `DATE` civil**, sem fuso e sem conversão — boleto que vence 10/03 vence 10/03 em qualquer fuso. Evita o bug clássico "venceu dia 9 às 21h".

## ADR-009 — Backup: snapshot diário cifrado, chave fora do servidor

**Contexto:** o SQLite contém finanças, rotina da casa e metadados do cofre — backup é ativo sensível. Bucket externo (Backblaze B2, 10 GB grátis) pode vazar. Litestream v0.5+ removeu cifragem age (novo formato LTX, estado prático a confirmar).
**Opções:** snapshot diário cifrado (perda máx. 24h, simples, cifragem garantida) vs. Litestream contínuo (perda de segundos, mais partes móveis, cifragem incerta na versão atual).
**Decisão:** cron noturno: cópia íntegra do SQLite → cifra com **age** → sobe para B2 → retém 30 dias. Chave de decifragem em dois lugares: na VPS (para cifrar) e no gerenciador de senhas pessoal do dev (para restaurar — cópia inegociável). **Teste de restauração a cada 3 meses**; a primeira restauração faz parte do DoD da Fatia 0.
**Gatilho de revisão:** entrada das despesas (Fatia 5) → reavaliar Litestream para reduzir a janela de perda.

## ADR-010 — Listas de compras: texto livre + autocomplete, sem catálogo, sem tempo real

**Contexto:** preenchimento não pode ser massante. Catálogo formal de produtos gera fricção ("produto novo ou existente?") e manutenção de duplicatas. A máquina de recorrência só nasce na Fatia 5 — a Fatia 2 não pode depender dela.
**Decisão:**
1. Item é **texto livre com autocompletar do histórico** (sugere nome e pré-preenche qtd/unidade/preço da última vez). Campos qtd, unidade, preço estimado, prioridade e loja: todos opcionais.
2. Compra recorrente v1 = botão **"duplicar lista"**. Lista perene = lista que nunca se arquiva. Sem tipos especiais.
3. **Sem tempo real** (provavelmente nunca): TanStack Query com refetch-ao-focar basta. Acesso no mercado = site mobile-first + bot de consulta.

**Gatilho catálogo:** demanda real por histórico de preço por produto.

## ADR-011 — Bot: vinculação por deep link, casa ativa

**Contexto:** MiCasa conhece "Carlos, admin da casa X"; Telegram conhece `chat_id`. Vinculação precisa ser segura e sem digitação manual.
**Decisão:** fluxo canônico de deep linking: perfil web (autenticado) → "Vincular Telegram" → token de uso único, 10 min → link `t.me/<bot>?start=TOKEN` → bot recebe `/start TOKEN` + `chat_id` → grava vínculo, invalida token. Whitelist = conjunto de `chat_id` vinculados; desconhecido é descartado e logado, **sem resposta**. Desvincular: o próprio membro ou admin. Multi-casa: uma casa → direto; várias → bot pergunta uma vez e guarda "casa ativa", trocável via `/casa`.

## ADR-012 — Recorrência: dia 31 ajusta; feriados via Yasumi atrás de serviço próprio

**Decisão:**
1. Recorrência mensal em dia inexistente **ajusta para o último dia do mês** (31/jan → 28/fev → 31/mar; não fica preso no 28). Comportamento de assinaturas reais. Caso de teste obrigatório do Auditor de Lógica.
2. Vencimento em fim de semana/feriado **antecipa** para o dia útil anterior. Feriados nacionais via biblioteca **Yasumi**, encapsulada num serviço próprio (`BusinessDayService`) — regra testável, fonte trocável.

**Ressalva registrada:** Carnaval é ponto facultativo, não feriado nacional; bancos fecham. Se incomodar, ajustar para calendário bancário — é configuração, não arquitetura.

## ADR-013 — Divisão de despesas: fora de escopo (decisão de produto, definitiva)

**Contexto:** análise apresentada — divisão exige cotas, livro-razão, acertos e arredondamento determinístico (~4–6 semanas). O caso de uso é orçamento familiar comum: membro nunca "deve" a outro membro, não há ressarcimento.
**Decisão do dev:** funcionalidade removida do escopo **sem gatilho de retorno**. Despesa é sempre da casa.
**Consequências:** modelo de domínio simplificado. (O schema — centavos, "quem pagou" opcional — não bloquearia uma extensão futura, mas ela não está planejada.)

## ADR-014 — TOTP/2FA: cofre anota onde o 2FA vive; MiCasa sem 2FA próprio na v1

**Contexto:** guardar sementes TOTP transformaria o cofre de metadados em cofre de segredos reais (quem tem a semente gera os códigos para sempre) — incoerente com a recusa de guardar senhas.
**Decisão:**
1. Cofre **nunca** guarda sementes TOTP. Campo "2FA" é anotação de localização ("app no celular do pai", "SMS", "não tem").
2. Login do MiCasa sem 2FA na v1 — senha forte + rate limit nas rotas de auth. Gatilho: acesso por gente de fora da família, ou tentativa real de invasão.

## ADR-015 — Despesa: entidade única com tipo; valores em centavos

**Contexto:** conta, boleto, assinatura e fatura de cartão modeladas como a mesma coisa (decisão do dev na entrevista inicial).
**Decisão:**
1. Entidade **Despesa** com campo `tipo` (boleto, assinatura, cartão, etc.). Recorrência da forma mais simples na v1; complexidade depois.
2. Separação **definição vs. ocorrência**: "luz mensal" é definição; "luz de mar/2026, R$ 187,40, paga dia 12" é ocorrência. Ocorrências materializadas com 12 meses de antecedência por job — nunca infinito, nunca calculado em tempo real.
3. Valores em **centavos (`integer`)**, nunca float. Exibição sempre em BRL formatado (`1.000,00`). Formatação é responsabilidade da borda (front/bot), nunca do banco.
4. Registro de pagamento: feito/não feito + data; comprovante e "quem pagou" opcionais. Soft delete + auditoria (quem criou/alterou/quando) em tudo que é financeiro.

## ADR-016 — Bot v1: Telegram, comandos estruturados, consulta-somente

**Contexto:** WhatsApp descartado (Cloud API exige template aprovado para mensagem iniciada pelo negócio — mata os lembretes; libs não-oficiais arriscam banimento). Sem orçamento para LLM.
**Decisão:**
1. Canal único: **Telegram**, atrás de `ChannelInterface` (abstração desde o dia 1; `ConsoleChannel` para testes).
2. Parser v1: **comandos estruturados** (`/lista`, `/tarefas`, `/ajuda`). A costura `IntentResolver` fica pronta para um `LlmParser` opcional no futuro (gratuito ou não entra); se a API de LLM cair, o estruturado continua.
3. Bot **só consulta e envia lembretes** na v1 — nunca cria/edita/apaga. Consequência aceita conscientemente: "marcar item pelo Telegram" não existe na v1; marca-se pelo site. Escrita via bot é v2 (com confirmação por botão inline quando vier).
4. Segurança: webhook valida `secret_token` (sem token → 403 sem processar); idempotência por `update_id`; webhook responde 200 rápido e joga processamento na fila (driver `database`); rate limit.
5. **O bot não consome a API HTTP** — chama as mesmas Actions da camada web. Regra duplicada entre web e bot é o bug mais provável do projeto.

## ADR-017 — Tarefa, evento e lembrete: tabela única com discriminador

**Decisão:** uma tabela (`activities` ou similar) com discriminador de tipo. Responsável obrigatório em tarefa (alterável), prazo opcional. Rodízio automático fica fora da v1 (sem demanda no primeiro cenário de uso). Recorrência de tarefas liga somente depois que o serviço de recorrência nascer na Fatia 5 — Fatia 3 entrega tarefas avulsas.

## ADR-018 — Ferramental (proposto na Fase 0, aprovado com o plano)

**Backend:** Pest (testes), Laravel Pint (formatação), Larastan nível 6+ (estática), `Model::preventLazyLoading()` em dev, filas com driver `database` (Redis vetado sem medição).
**Frontend:** Vite, React Router, TanStack Query, React Hook Form + Zod, Tailwind, Vitest + Testing Library (libs entram conforme ADR-006).
**CI (GitHub Actions):** Pint + Larastan + Pest + `tsc` + Vitest em cada push, com filtros de path do monorepo.
**Vetado sem discussão:** Livewire, libs não-oficiais de WhatsApp, ORM alternativo, microserviços, Docker multi-container.

---

## Decisões adiadas (com gatilho)

| Decisão | Estado | Gatilho para revisitar |
|---|---|---|
| Login com Google (Socialite) | Adiada | Família reclamando de senha, ou chegada da integração com agenda |
| Sincronização Google Agenda vs. exportar `.ics` | Adiada | Fatia 7 (agenda) |
| PWA instalável / offline de leitura | v2 | Fim da v1 |
| Escrita via bot (marcar item, criar tarefa) | v2 | Fim da v1; se a família sentir falta antes, vira Fatia 5.5 |
| LLM no parser do bot | v2, condicional | Existir opção gratuita confiável; estruturado permanece como fallback |
| Rodízio automático de tarefas | v2 | Demanda real da família |
| Litestream (backup contínuo) | Adiada | Fatia 5 (despesas) — reavaliar janela de perda de 24h |
| Catálogo de produtos | Adiada | Demanda por histórico de preço por produto |
| 2FA no login do MiCasa | Adiada | Acesso externo à família, ou tentativa de invasão |
| i18n no front | Adiada | Usuário não-lusófono, ou portfólio internacional |
| Geração automática de lista recorrente | Adiada | Depois da Fatia 5, se "duplicar lista" não bastar |
