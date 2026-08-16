# Gate A2 — Existing Infrastructure Compatibility Decision

**FirmsVault Pay — Finix Sandbox POC #1**
Master Execution Prompt v1.4 FINAL · Architecture v3.1 FROZEN
Branch `feature/firmsvault-pay-finix-poc1` · Base `admin/final-reconciliation` @ `bb52cdb`

This is the **first substage** deliverable required by v1.4 §3: prove what can be reused
*before* creating anything. Every ruling below was reached by reading the existing
implementation, not by matching names.

---

## Summary of rulings

| Existing component | Architecture role | Ruling | One-line reason |
|---|---|---|---|
| `IntegrationProvider` / `integration_providers` | `ProviderPlatformConnection` | **REUSE AS-IS** | Seeded closed catalog, already the platform-level provider identity. No parallel table created. |
| `FirmIntegration` / `firm_integrations` | `FirmProviderAccount` | **REUSE AS-IS** | Already the firm↔provider account, FORCE RLS, already the composite-FK parent every integration child uses. |
| `integration_webhook_routing_index` | `ProviderResourceLocator` | **EXTEND** | Correct security shape already; needed a second addressing mode for provider-minted resource ids. |
| `IntegrationExternalMapping` | `ProviderResourceMapping` | **REUSE AS-IS** | Already non-authoritative for tenant ownership. Untouched. |
| `provider_operation_attempts` | at-most-once **send gate** | **REUSE AS-IS (unchanged)** | Best-in-repo at-most-once engine. Not modified in any way. |
| `provider_operation_attempts` | `ProviderCommand` | **INCOMPATIBLE for this role** → new `provider_commands` **WRAPS** it | "Survives caller rollback" and "commits atomically with the caller" are contradictory requirements. See below. |
| `integration_outbox_events` | `OutboxMessage` | **REUSE AS-IS** | Used unchanged via `recordOnce()`. No payment-specific outbox created. |
| `accounting_journal_entries` / `accounting_postings` | ledger | **REUSE + minimal EXTEND** | Reused via `AccountingJournalPostingService::post()`. One additive source-link column. |
| `ChartOfAccountPurpose` | chart of accounts | **EXTEND (2 cases)** | Enum has no DB check constraint; adding cases needs no migration. |
| `security_events` + recorders | audit | **EXTEND** | New `PayAuditRecorder` writes ordinary `SecurityEvent` rows under one category. |
| `domain_events` | event engine | **REUSE (no change needed)** | Gate A2 required no new event-engine capability. |
| `PaymentClassificationService` trust block | trust safety | **REUSE AS-IS (untouched)** | Existing unconditional block preserved; Pay adds an earlier, independent refusal. |
| `StripeGateway` | payment provider adapter | **NOT USED** | Provider-independent gate; no adapter work authorized (§15). Left exactly as found. |

---

## 1. The ProviderCommand ruling (v1.4 §9–§11) — the central decision

**Ruling: `provider_operation_attempts` is REUSED AS-IS as the at-most-once send gate, and a new
tenant-owned `provider_commands` table WRAPS it as the economic instruction.**

### Why equivalence was not assumed

v1.4 §9 forbids inferring equivalence from naming or general purpose. Reading
`ProviderOperationAttemptService` in full shows it satisfies almost every required semantic:

| Required semantic (§9) | `provider_operation_attempts` |
|---|---|
| immutable logical command identity | ✅ `logical_operation_key`, globally UNIQUE, deterministic hash of stable business inputs, never wall-clock |
| firm ownership | ⚠️ scalar `firm_id` only — deliberately **no FK** |
| provider account ownership | ⚠️ scalar `firm_integration_id` only — deliberately **no FK** |
| aggregate identity | ⚠️ `operation_type` only; no aggregate type/id pair |
| canonical payload hash | ❌ absent — `result_checksum` hashes the *response*, not the instruction |
| same key + same payload semantics | ❌ no payload concept at all |
| same key + different payload conflict | ❌ cannot be detected — nothing to compare |
| provider reference | ✅ `provider_request_reference` |
| command status | ✅ 9-state machine |
| provider outcome uncertainty | ✅ `provider_outcome_uncertain` — genuinely first-class |
| reconciliation-required state | ✅ `reconciliation_required`, operator-only exit |
| crash recovery | ✅ lease sweep, two provably-safe transitions only |
| queue retry behavior | ✅ claim/lease/CAS |
| duplicate worker safety | ✅ `send_count` CAS, invariant ≤ 1 |
| provider timeout behavior | ✅ never assumed billable |

### The structural incompatibility

The gate is written on the **independent `pgsql_audit` connection, in autocommit, with no
transactions and no foreign keys** — deliberately, so its evidence **survives a rollback of the
caller's transaction**. Its own migration records that Checkpoint 8.1's FK-bearing variant was
**rejected after a proven production deadlock** (`PullSyncJob` holds `FOR UPDATE` on
`firm_integrations`; a cross-session INSERT whose composite FK references that locked row must take
`FOR KEY SHARE`, which is incompatible — observed live via `pg_stat_activity`/`pg_locks`).

v1.4 §14 requires the ProviderCommand to be created **inside** the financial domain transaction and
to **commit atomically with it**, so no economic instruction can outlive a rolled-back financial
transaction.

> **"Survives rollback" and "commits atomically with the transaction" are contradictory
> requirements on one row.**

They are therefore two rows, at two layers:

```
provider_commands            ← the ECONOMIC INSTRUCTION
  tenant-owned · FORCE RLS · real composite FKs · immutable envelope
  · atomic with the domain transaction and the outbox row

provider_operation_attempts  ← the DURABLE AT-MOST-ONCE SEND GATE  (REUSED, UNCHANGED)
  independent connection · autocommit · no FKs · survives caller rollback
  · consulted at the instant of the outbound call
```

The two are bound deterministically: `ProviderCommand::logicalOperationKey()` returns
`fvpay:<command_uuid>`. The worker never invents a key, so one economic instruction maps to exactly
one gate row and a second worker can never obtain a second send permission.

**This is not a second command engine.** Nothing in `app/Integrations/Billing` was modified, and
`provider_commands` adds precisely what the gate was always missing: a tenant-owned, transactional,
immutable instruction to point at. Proved by `FV-A2-002`, which asserts the gate's table still
exists with its Global/EXEMPT posture intact.

### At-most-once vs safe retry (v1.4 §10) — failure-window analysis

The existing invariant `send_count <= 1` was **not weakened and not reused blindly**. Each named
window resolves as follows, using the existing engine's own transitions:

| Failure window | Existing behavior | Verdict |
|---|---|---|
| worker claims → crash before HTTP call | row stays `claimed`; lease expires; sweeper → `retry_allowed`. `send_count = 0` **proves** nothing left the process | ✅ safe retry, no lost command |
| worker calls provider → crash before recording response | row stays `attempt_started`; lease expires → `provider_outcome_uncertain` → `reconciliation_required` | ✅ no second charge |
| HTTP timeout, provider may have processed | `recordProviderOutcomeUncertain()` → `reconciliation_required`; never auto-retried | ✅ no second charge |
| provider succeeds → local transaction fails after | `recordProviderSucceeded()` is written **before** local post-processing; `markLocalProcessingFailed()` preserves it; resume completes local work **without** re-calling the provider | ✅ no second charge, no lost work |

The dangerous direction — "permanently losing a command that was never actually delivered" — is
covered by the first row: the only automated path back to sendable is from a state that
*positively proves* no billable work occurred (`claimed` with `send_count = 0`, or
`provider_rejected`). Everything else requires an audited operator resolution. That is the correct
trade: an unnecessary reconciliation costs human time; an unnecessary retry costs a client's money.

---

## 2. The ProviderResourceLocator ruling (v1.4 §5–§7)

**Ruling: EXTEND `integration_webhook_routing_index`. No second ownership table.**

Gate A1 found this table already performs pre-tenant provider-resource → tenant resolution with the
right security properties: no RLS (documented at length, specifically to avoid a `SECURITY DEFINER`
function or a session-GUC carve-out, both explicitly rejected in its frozen design), no secret
material, a single writer, a single reader, and a **composite FK** that already makes
`Locator → Firm A` / connection `→ Firm B` unrepresentable.

Its one gap: it is keyed on a **FirmsVault-issued** routing token hash. A payment provider mints its
own resource ids, so there is no token to hash. The repository had already hit this twice and
answered with dedicated per-provider side tables (`integration_gmail_mailbox_routes`,
`integration_plaid_item_routes`). A third bespoke table would have entrenched exactly the
fragmentation §6 forbids.

So the **one** authority gained a second addressing mode rather than a sibling gaining a competing
one:

```
mode A (existing, unchanged):  webhook_routing_token_hash             → (firm, connection)
mode B (new):                  (provider, resource_type, resource_id) → (firm, connection)
```

- A `CHECK` constraint guarantees every row is in exactly one mode.
- The original global uniqueness on the token hash is preserved as a partial unique index.
- Ownership uniqueness is a partial unique index on
  `(integration_provider_id, provider_resource_type, provider_resource_id)`.
- That index **deliberately does not exclude inactive rows**, so `ACTIVE → INACTIVE` is permitted
  while `Firm A → Firm B` stays impossible — including via the realistic
  deactivate-then-reclaim route (`FV-A2-011`).
- Existing reads are unaffected: `NULL = <digest>` is never true, so mode-B rows are invisible to
  the token lookup.

### One necessary correction to existing code

`ProviderConnectionService` deletes routing rows on enable/disable/disconnect, filtered only by
`firm_integration_id`. Once the table also holds ownership rows, those three mass deletes would have
silently destroyed historical ownership. All three were narrowed with
`->whereNull('provider_resource_id')`. This is the minimum necessary safe change contemplated by
v1.4 §55, and it is the only behavioral edit made to existing integration code.

---

## 3. Outbox ruling (v1.4 §14)

**Ruling: REUSE `integration_outbox_events` unchanged.** `IntegrationOutboxEventService::recordOnce()`
is called with `domain_event_id = <command uuid>`. Because that table already carries
`UNIQUE (firm_id, domain_event_id)`, **the database** — not application code — makes duplicate
dispatch of one economic instruction impossible (`FV-A2-007`). No payment-specific outbox exists.

---

## 4. Ledger ruling (v1.4 §29–§34)

**Ruling: REUSE the existing double-entry ledger. No second payment ledger.**

All Pay postings go through `AccountingJournalPostingService::post()`, inheriting its
balanced-journal validation, same-firm account check, closed-period guard and partial unique index
on `(firm_id, idempotency_key)`.

Two minimal additive changes:

1. `accounting_journal_entries.payment_attempt_id` — a provider capture posts *before* (and possibly
   without) any canonical `Payment`, so it needs its own source link. Unlike its sibling source
   columns it uses a **composite FK**, because v1.4 §35 requires DB-enforced tenant consistency for
   every new relationship (`FV-A2-064`).
2. `post()` passes that key through from `$sourceRefs`. Every existing caller omits it and keeps
   getting `null`.

**Accounts Receivable was deliberately NOT introduced** (§29). The repository recognizes revenue at
cash receipt and posts no AR at invoice issuance; that basis is preserved exactly.

---

## 5. Audit ruling (v1.4 §43)

**Ruling: EXTEND `security_events`.** No Finix/Pay audit subsystem.

One correction was forced by observed behavior: an audit row recording a **refusal** is written
immediately before an exception, and that exception rolls back the caller's transaction — taking
the audit row with it. The audit of the most security-relevant events was the one guaranteed to
vanish. `PayAuditRecorder` therefore writes refusal events on the independent `pgsql_audit`
connection, reusing `TimelineEventRecorder::recordOnIndependentConnection()`'s established
mechanism verbatim, and never lets an audit failure mask the domain exception it records. Proved by
`PayRefusalAuditDurabilityTest`.

---

## 6. What was NOT reused, and why

| Component | Why not |
|---|---|
| `payments` as `PaymentAttempt` | `PaymentStatus` has no `submitted`, no `declined` distinct from `failed`, no `cancelled`, no `outcome_unknown`. Adding them would change the meaning of a column the whole Billing domain, Filament UI and reporting layer branch on, for every historical row. |
| `payment_allocations` as intent allocations | Allocates an already-received `Payment` across invoices; no destination class, different parent. Conflating executed with proposed allocation. |
| `payment_reversals` as provider refunds | Records a refund that already happened in the firm's books; no reservation, no command identity, no unresolved-outcome state. |
| `StripeGateway` | Out of scope: Gate A2 is provider-independent (§15). Left exactly as found. |
