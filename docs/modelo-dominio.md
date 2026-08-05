# MiCasa — Modelo de Domínio

> Nomes de entidades em inglês (convenção de código); conceitos explicados em pt-BR. Este documento descreve o domínio, não o schema final — colunas exatas nascem nas migrations de cada fatia, mas as **invariantes daqui são lei**.

## Entidades e relacionamentos

```
User ─────────┬───< HouseholdUser >───┬───── Household
              │      (papel por casa)  │        │ timezone
              │                        │        │
  telegram_chat_id                     │        ├──< Invitation (token, expira, revogável)
  active_household_id                  │        ├──< ShoppingList ──< ShoppingListItem
                                       │        ├──< Activity (task | event | reminder)
                                       │        ├──< Expense (definição) ──< ExpenseOccurrence
                                       │        └──< VaultItem
```

- **User** — pessoa. Global, não pertence a casa nenhuma diretamente. Carrega o vínculo Telegram (`telegram_chat_id`, único, nullable) e a casa ativa para contexto do bot/UI.
- **Household** — a casa. Tem `timezone` (padrão `America/Sao_Paulo`). Toda entidade de domínio pertence a exatamente uma casa.
- **HouseholdUser** (pivô) — vínculo pessoa↔casa com `role` (`admin` | `member`). Uma pessoa pode estar em várias casas com papéis diferentes.
- **Invitation** — token de uso limitado que insere quem o abre como `member` da casa. Expira; revogável por admin.
- **ShoppingList / ShoppingListItem** — item é texto livre (nome) + campos opcionais: quantidade, unidade, preço estimado em **centavos**, prioridade, loja; `checked_at` + quem marcou. Lista pode ser arquivada e duplicada.
- **Activity** — tarefa, evento ou lembrete, discriminados por `type`. Tarefa: responsável (User da casa, obrigatório, alterável), prazo opcional (data civil), `completed_at` + quem concluiu. Evento: início/fim (UTC). Lembrete: quando disparar + canal (bot).
- **Expense** (definição) — "conta de luz, mensal, vence dia 10". Campos: nome, `type` (boleto, assinatura, cartão, outro), periodicidade simples v1 (mensal | anual | única; parcelada com fim previsto), dia de vencimento, valor padrão em centavos (nullable para valor variável).
- **ExpenseOccurrence** — "luz de mar/2026, R$ 187,40, paga em 12/03". Campos: `due_date` (data civil, **já ajustada** para dia útil), valor em centavos, `paid_at` (nullable), quem pagou (opcional), comprovante (opcional). Gerada por job; editável individualmente sem afetar a definição.
- **VaultItem** — metadados: serviço, login, **onde a senha está guardada** (texto), anotação de onde vive o 2FA. **Nunca** senha, nunca semente TOTP.

## Serviços de domínio

- **RecurrenceService** (nasce na Fatia 5) — único para despesas, tarefas e lembretes. Materializa ocorrências com horizonte de **12 meses**, via job agendado. Nunca infinito, nunca em tempo real.
- **BusinessDayService** — encapsula Yasumi (feriados nacionais). Regra: vencimento em fim de semana/feriado **antecipa** para o dia útil anterior.
- **Actions** — toda regra de negócio vive em Actions/Services compartilhados entre controller web e bot. O bot **não** consome a API HTTP.
- **Bot pipeline** — `ChannelInterface` → `IncomingMessage` (DTO) → `IntentResolver` (parser estruturado; costura pronta para LLM) → `Intent` tipado → Action → resposta.

## Invariantes de negócio (lei — o Revisor reprova violação)

1. **Dinheiro é `integer` em centavos.** Formatação BRL (`1.000,00`) é responsabilidade da borda. Float em valor monetário é bug, não estilo.
2. **Timestamps em UTC; vencimentos em data civil** (`DATE` sem fuso). Conversão de exibição usa o `timezone` da casa.
3. **Recorrência mensal em dia inexistente ajusta para o último dia do mês** — e volta ao dia original quando o mês permite (31/jan → 28/fev → 31/mar).
4. **Toda query é escopada pela casa** (e pelo papel, quando aplicável). Policy em toda entidade. Membro de A jamais lê recurso de B — testado explicitamente, por fatia.
5. **Ocorrência é imutável pela definição:** editar a definição afeta apenas ocorrências futuras não-materializadas; a ocorrência editada à mão não é sobrescrita pelo job.
6. **Financeiro tem soft delete + auditoria** (quem criou/alterou/quando). "Quem marcou essa conta como paga?" tem resposta.
7. **Bot: whitelist por vínculo.** `chat_id` desconhecido → descarte + log, sem resposta. Idempotência por `update_id`.
8. **Cofre nunca contém segredos** — é invariante de dados, não de tela: validação/rejeição no backend, com rigor de authz (admin gerencia; membro lê).
9. **Nada sensível em log** (nem cofre, nem valores, nem PII além do necessário).

## Extensões previstas (não construídas)

- `LlmParser` plugando no `IntentResolver` (ADR-016).
- Escrita via bot com confirmação inline (v2).
- Sync Google Agenda (decisão na Fatia 7).
