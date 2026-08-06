# MiCasa

Sistema de gestão doméstica multi-casa — listas de compras, tarefas, despesas, agenda, cofre de metadados e bot Telegram de consulta. Monorepo: API Laravel (`api/`) + SPA React/TypeScript (`web/`).

O projeto tem dois objetivos simultâneos: ser **usado de verdade por uma família** e servir de **trilha de aprendizado de React + TypeScript** para um dev PHP experiente.

## Documentação — leia antes de qualquer mudança

| Documento | Conteúdo |
|---|---|
| [docs/como-executar-e-testar.md](docs/como-executar-e-testar.md) | **Comece aqui:** setup, execução local e testes — atualizado a cada fatia |
| [docs/decisoes.md](docs/decisoes.md) | ADRs — toda decisão de arquitetura, com contexto e consequências. **Decisão fechada não se reabre sem gatilho.** |
| [docs/escopo-v1.md](docs/escopo-v1.md) | O que entra na v1 e, mais importante, o que fica de fora e por quê |
| [docs/modelo-dominio.md](docs/modelo-dominio.md) | Entidades, relacionamentos e as invariantes de negócio que são lei |
| [docs/plano-fatias.md](docs/plano-fatias.md) | As fatias verticais de execução e o estado de cada uma |
| [prompt-casa-os.md](prompt-casa-os.md) | O contrato original do projeto: regras de atuação, agentes, Definition of Done |

## Âncoras técnicas (resumo — detalhes nos ADRs)

- PHP / Laravel / SQLite (WAL) · TypeScript / React / Vite
- API + SPA com Sanctum (modo cookie), **não** Inertia — ADR-001
- Valores monetários em centavos (`integer`); UTC no banco, vencimentos em data civil
- Deploy: Oracle Always Free (ARM), backup diário cifrado em Backblaze B2 — ADR-003/009
- Bot Telegram consulta-somente na v1, comandos estruturados — ADR-016
