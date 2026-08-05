# MiCasa — Escopo da v1

> A lista do que **fica de fora** é mais importante que a do que entra. Cada exclusão tem justificativa e, quando aplicável, gatilho de retorno (ver ADRs em `decisoes.md`).

## O que ENTRA na v1

**Fundação**
- Monorepo (`api/` Laravel + `web/` React/TS/Vite), CI no GitHub Actions, deploy na Oracle Always Free com script de provisionamento, backup diário cifrado com restauração testada. Deploy funcionando desde a Fatia 0.
- Autenticação e-mail + senha (Sanctum SPA), rate limit em auth e webhook.

**Casas e pessoas**
- Múltiplas casas; cadastro público cria casa; convite por link com token; papéis admin/membro por casa (pivô); seletor de casa ativa; policies por casa em toda entidade.

**Listas de compras**
- CRUD completo, texto livre com autocompletar do histórico, campos opcionais (qtd, unidade, preço, prioridade, loja), marcar comprado, duplicar lista, arquivar.

**Tarefas / eventos / lembretes**
- Tabela única com discriminador; responsável alterável; prazo opcional; filtros. Recorrência de tarefas só liga após a Fatia 5.

**Bot Telegram (consulta)**
- Vinculação por deep link; whitelist por vínculo; comandos estruturados de leitura (`/lista`, `/tarefas`, `/contas`, `/ajuda`, `/casa`); lembretes proativos (vencimentos, prazos); idempotência, fila, secret token.

**Despesas**
- Entidade única com tipo; definição vs. ocorrência (materialização 12 meses); centavos integer; vencimento como data civil; antecipação para dia útil anterior (feriados nacionais/Yasumi); registro de pagamento (data; comprovante e quem pagou opcionais); soft delete + auditoria.

**Agenda da casa**
- Visualização de eventos/tarefas/vencimentos; decisão `.ics` vs. sync Google tomada na Fatia 7.

**Cofre de metadados**
- Serviço, login, onde a senha está guardada, anotação de onde vive o 2FA. Tratado com rigor de authz, não como CRUD comum.

## O que FICA DE FORA da v1

| Item | Por quê | Retorno |
|---|---|---|
| **Divisão de despesas entre membros** | Decisão de produto: orçamento familiar comum, ninguém deve a ninguém. Subsistema contábil de 4–6 semanas sem caso de uso. | **Nunca** (ADR-013) |
| **Senhas e sementes TOTP no cofre** | Risco desproporcional; vazar o banco vazaria as contas da família. Só metadados. | Nunca |
| **Escrita via bot** | Dev decidiu bot consulta-somente, ciente de que "marcar item no mercado via Telegram" fica de fora. | v2; vira Fatia 5.5 se a família sentir falta |
| **WhatsApp** | API oficial mata lembretes (template aprovado); lib não-oficial arrisca banimento. | Só se a Cloud API mudar |
| **LLM no parser** | Sem orçamento; estruturado resolve v1. | v2, se houver opção gratuita confiável |
| **PWA / offline** | 100% online decidido; escrita offline com merge é projeto à parte. | v2 (leitura) |
| **Tempo real (websockets)** | Refetch-ao-focar cobre o caso família. Provavelmente nunca necessário. | Só com dor real medida |
| **Rodízio automático de tarefas** | Complexidade sem demanda no cenário inicial. | v2 |
| **Login Google / sync Google Agenda** | Independentes entre si; auth por senha é o conteúdo de vaga. | Gatilhos no ADR-004 / Fatia 7 |
| **Linha digitável / código de barras de boleto** | Escopo cortado na entrevista. | — |
| **i18n no front** | Fricção diária na fase de aprendizado; retrofit barato. | Gatilho no ADR-005 |
| **2FA no login do MiCasa** | Desproporcional para app de família com rate limit. | Gatilho no ADR-014 |
| **Catálogo de produtos** | Fricção de manutenção; autocomplete do histórico entrega 80%. | Demanda por histórico de preço |
| **Redis, cache, índices extras** | Otimização prematura; driver `database` basta. | Só com medição |

## Restrições operacionais assumidas

- 5h/semana de dedicação; sem prazo — mas cada fatia termina com a pergunta "a família está usando?"; se não, parar e descobrir por quê.
- VM descartável (Oracle pode recuperar): dados protegidos por backup off-site cifrado + provisionamento por script; plano B Hostinger documentado.
- Mobile-first: uso real é no celular, em pé, na cozinha.
