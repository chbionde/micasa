# MiCasa — Plano de Fatias Verticais

> Cada fatia é ponta a ponta: migration → action → teste → endpoint → tela → teste de front → revisão → **produção**. Definition of Done completa em `prompt-casa-os.md` §9. Ao fim de cada fatia: "a família está usando?" — se não, parar e investigar antes da próxima.

| # | Entrega | Conteúdo de aprendizado React/TS | Agentes de revisão extras |
|---|---|---|---|
| 0 | Monorepo, CI (Pint+Larastan+Pest+tsc+Vitest), auth e-mail+senha (Sanctum SPA), layout base, deploy Oracle + script de provisionamento, backup cifrado + **1ª restauração testada** | Setup Vite/TS, estrutura de SPA, fluxo de auth com cookie/CSRF | Adversário de Segurança (auth) |
| 1 | Casas, membros, convite por link, papéis, policies, seletor de casa ativa | React Router, contexto de auth/casa ativa (`useContext`) | Adversário de Segurança (authz/IDOR) |
| 2 | Listas de compras: CRUD, autocomplete do histórico, duplicar, arquivar | **RHF + Zod** (antecipado da Fatia 3 — ver emenda do ADR-006) | — |
| 3 | Tarefas/eventos/lembretes (tabela única), responsável, filtros — sem recorrência | Tipos derivados do contrato, filtros com estado de URL | — |
| 4 | Bot Telegram: webhook seguro, deep link de vinculação, comandos de consulta, `ConsoleChannel` de teste | (pouco front) TS no contrato dos DTOs | Adversário de Segurança (webhook/whitelist) |
| 5 | Despesas: definição/ocorrência, RecurrenceService, BusinessDayService, materialização 12 meses, pagamento | Tabelas/listagens com Query, formulários monetários (centavos na borda) | Auditor de Lógica (obrigatório) + reavaliar Litestream (ADR-009) |
| 6 | Lembretes proativos via bot (vencimentos, prazos) — bot envia, não escreve | — | Auditor de Lógica (fuso/agendamento) |
| 7 | Agenda da casa (visão consolidada); decidir `.ics` vs. sync Google | Composição de componentes, visão calendário | — |
| 8 | Cofre de metadados | Revisão geral de forms/tipos | Adversário de Segurança (obrigatório) |
| — | **v2:** PWA, escrita via bot, rodízio, LLM parser, Google, Litestream | | |

## Regras de execução

- Recorrência de tarefas liga só depois da Fatia 5 (o serviço nasce lá).
- Fatias 2–3 carregam o custo do método híbrido (ADR-006): ~1 sessão extra por conceito, com commit da versão manual antes da migração para lib.
- Revisor de Código revisa toda fatia; veto máximo 2 ciclos, depois escala ao dev.
- Nenhuma fatia começa antes da anterior estar **em produção**.
