# Gate A2 — Acceptance Test Registry & Database Invariant Matrix

**FirmsVault Pay — Finix Sandbox POC #1** · Master Execution Prompt v1.4 FINAL

All Gate A core tests are **CERTIFICATION_BLOCKING** and must PASS before Gate A3 / Finix work.
Every test below runs against **real PostgreSQL 16** via
`tools/rls-test/run-artisan-test.sh` against a disposable, sentinel-tagged database
(v1.4 §53). No SQLite anywhere.

---

## A. Database Invariant Matrix (v1.4 §44)

| # | Invariant | Table(s) | Database mechanism | Application mechanism | Failure behavior | Acceptance test |
|---|---|---|---|---|---|---|
| 1 | Provider command identity is unique per firm | `provider_commands` | `UNIQUE (firm_id, idempotency_key)` | `ProviderCommandService::createOrReuse()` insert-then-arbitrate | Unique violation → reuse or conflict | FV-A2-004 / 005 |
| 2 | Same key + different payload never executes | `provider_commands` | stored `canonical_payload_hash` compared under the unique index | `hash_equals()` then throw | `IdempotencyConflictException`, no 2nd row | FV-A2-005 |
| 3 | Command envelope is immutable | `provider_commands` | — | model `updating` guard on `ENVELOPE_FIELDS` | `LogicException` | FV-A2-003 |
| 4 | Command can never be deleted | `provider_commands` | — | model `deleting` guard | `LogicException` | FV-A2-003 (delete case) |
| 5 | Provider-resource ownership is unique | `integration_webhook_routing_index` | partial `UNIQUE (integration_provider_id, provider_resource_type, provider_resource_id)` | `ProviderResourceOwnershipService::establishOwnership()` | Unique violation → conflict | FV-A-038 |
| 6 | Concurrent conflicting ownership → exactly one winner | same | same unique index, under real contention | insert-then-arbitrate | loser rejected; 1 row survives | **FV-A-039** (pcntl fork) |
| 7 | Ownership immutable; inactive ≠ reassignable | same | unique index **not** filtered on `ownership_status` | model `updating`/`deleting` guards | `LogicException` / conflict | FV-A2-010 / 011 |
| 8 | Every row has exactly one addressing mode | same | `CHECK` (token XOR resource) | service always sets one shape | check violation | covered by 5–7 |
| 9 | Business mapping cannot assign ownership | `provider_commands` | composite FK `(firm_id, firm_integration_id)` | no ownership columns exist on mappings | FK violation | FV-A2-012 |
| 10 | Tenant-consistent new relationships | `payment_intent_allocations`, `payment_attempts`, `payment_refunds`, `provider_commands`, `provider_evidence_artifacts`, `accounting_journal_entries` | composite FKs to `(id, firm_id)` / `(firm_id, id)` | services always run in firm context | FK violation | FV-A2-062 / 063 / 064 |
| 11 | Journal posting idempotency | `accounting_journal_entries` | existing partial `UNIQUE (firm_id, idempotency_key)` | deterministic key `provider_payment_captured:payment_attempt:<id>` | returns original entry | FV-A2-045 / 046 |
| 12 | Refund reservation never over-reserves | `payment_refunds` | `SELECT … FOR UPDATE` on parent `payment_attempts` row | `RefundReservationService::reserve()` | `RefundCapacityExceededException` | **FV-A2-051 / 052** (pcntl fork) |
| 13 | A refund holds exactly one command | `payment_refunds` | `UNIQUE (provider_command_id)` | state machine | unique violation | FV-A2-054 |
| 14 | An attempt holds exactly one command | `payment_attempts` | `UNIQUE (provider_command_id)` | write-once model guard | unique violation | FV-A2-028 |
| 15 | USD only | `payment_intents`, `payment_attempts`, `payment_refunds` | `CHECK (currency = 'USD')` | service argument validation | check violation | FV-A2-021 |
| 16 | Amount strictly positive | same | `CHECK (amount_cents > 0)` | service argument validation | check violation | FV-A2-022 |
| 17 | Freeze integrity | `payment_intents` | `CHECK` freeze-consistency + `material_fingerprint` | model guard on `MATERIAL_FIELDS` | `LogicException` | FV-A2-025 |
| 18 | Supersede integrity, same firm | `payment_intents` | `CHECK` supersede-consistency, self-referencing composite FK, no-self-supersede `CHECK` | `supersede()` under row lock | check/FK violation | FV-A2-026 |
| 19 | Allocation completeness | `payment_intent_allocations` | append-only rows + frozen parent (cross-row SUM cannot be a row `CHECK`; zero-trigger convention) | `freeze()` asserts under `FOR UPDATE` | `PaymentIntentNotExecutableException` | FV-A2-023 / 024 |
| 20 | Trust execution prohibition | `provider_commands` | `CHECK command_type IN ('capture_payment','refund_payment')` — no trust value exists | `PaymentAttemptService::open()` refuses any trust allocation | `TrustExecutionDisabledException` | FV-A2-030 – 033 |
| 21 | Unknown outcome requires reconciliation | `provider_commands` | `CHECK (status <> 'outcome_unknown' OR reconciliation_required)` | transition sets the flag | check violation | FV-A2-028 |
| 22 | Only a captured attempt links a Payment | `payment_attempts` | `CHECK (payment_id IS NULL OR state = 'captured')` | posting service refuses non-captured | check violation | accounting suite |
| 23 | Capacity-holding refund carries evidence | `payment_refunds` | `CHECK` reservation-evidence | `resolve()` never clears `reserved_at` | check violation | FV-A2-053 |
| 24 | Event/outbox dedupe | `integration_outbox_events` | existing `UNIQUE (firm_id, domain_event_id)` | `recordOnce()` with command uuid | unique violation | FV-A2-006 / 007 |
| 25 | New tenant tables are RLS + FORCE RLS | all 6 new tables | `ENABLE` + policy + `FORCE ROW LEVEL SECURITY` | registry registration | contextless read = 0 rows; write refused | FV-A2-060 / 061 |

### Known DB-enforcement limitations (disclosed, not worked around)

| Invariant | Enforced by | Why not a database mechanism |
|---|---|---|
| Ownership not mutated **in place** | application (`updating` guard) | This codebase has a standing **zero-trigger** convention, stated verbatim in `2026_09_05_054001_create_integration_conflicts_table.php`; and column-level `REVOKE UPDATE` does not bind the **table owner**, which is the role both the application and the test harness connect as. The realistic attack (deactivate → reclaim) *is* blocked in the database by the unique index. |
| Allocation completeness (cross-row SUM) | application, inside a `FOR UPDATE` transaction | A cross-row aggregate cannot be a row-level `CHECK`, and a trigger is excluded by the same convention. Immutability of frozen intent + append-only allocations keeps it true afterwards. |
| Refund capacity (cross-row SUM) | `SELECT … FOR UPDATE` protocol | Same. Proved under genuine two-process concurrency rather than asserted. |

---

## B. Acceptance Test Registry

Legend — **Class**: CB = CERTIFICATION_BLOCKING. **Provider dep**: none = provider-independent.

### Infrastructure (v1.4 §46)

| test_id | Gate | Class | Invariant | Enforcement | Provider dep | Fixture | Expected evidence | Allowed result |
|---|---|---|---|---|---|---|---|---|
| FV-A2-001 | A2 | CB | Existing `IntegrationProvider`/`FirmIntegration` mapped without a duplicate provider-account subsystem | schema absence + FK presence | none | — | no `provider_platform_connections` / `firm_provider_accounts` tables; `provider_commands` references both existing tables | PASS only |
| FV-A2-002 | A2 | CB | `provider_operation_attempts` compatibility decision proven; gate reused unchanged | `pg_class` posture + deterministic key | none | 1 command | gate table still Global/EXEMPT; `logicalOperationKey()` stable | PASS only |
| FV-A2-003 | A2 | CB | ProviderCommand immutable envelope enforced | model guard | none | 1 command | envelope edits refused; execution metadata still mutable | PASS only |
| FV-A2-004 | A2 | CB | same key + same payload is safe | `UNIQUE` + hash compare | none | 2 calls, keys reordered | same row id returned; 1 row total | PASS only |
| FV-A2-005 | A2 | CB | same key + different payload blocked | `UNIQUE` + hash compare | none | 2 calls, different amount | `IdempotencyConflictException`; original untouched; 1 row | PASS only |
| FV-A2-006 | A2 | CB | Command + outbox creation atomic with domain mutation | one `DB::transaction` | none | executable intent | attempt, command, 1 outbox row all present | PASS only |
| FV-A2-007 | A2 | CB | Outbox worker cannot duplicate a logical command | existing `UNIQUE (firm_id, domain_event_id)` | none | 1 command | duplicate insert rejected by DB | PASS only |

### Ownership (v1.4 §47)

| test_id | Gate | Class | Invariant | Enforcement | Provider dep | Fixture | Expected evidence | Allowed result |
|---|---|---|---|---|---|---|---|---|
| FV-A-038 | A | CB | Single authoritative provider-resource → tenant ownership source | partial `UNIQUE` + composite FK | none | 2 firms, 1 resource | 1 ownership row; 2nd owner refused at DB level | PASS only |
| FV-A-039 | A | CB | Concurrent conflicting assignment safe | same index under real contention | none | 2 firms, 2 OS processes (pcntl fork) | exactly 1 winner, 1 loser, 1 surviving active row, no dual ownership | PASS only |
| FV-A2-010 | A2 | CB | Ownership immutable after establishment | model guards | none | 1 ownership row | in-place reassignment and deletion refused | PASS only |
| FV-A2-011 | A2 | CB | Inactive historical resource cannot be reassigned | unique index not filtered on status | none | deactivated ownership | resolve → null; reclaim by other firm → conflict | PASS only |
| FV-A2-012 | A2 | CB | Business mapping cannot conflict with routing ownership | composite FK | none | 2 firms | command naming a foreign provider account rejected | PASS only |
| FV-A2-066 | A2 | CB | Routing resolver is not a cross-tenant financial gateway | bounded return shape | none | 1 ownership row, no context | returns 4 routing fields only; 0 rows readable from financial tables | PASS only |

### Payment core (v1.4 §48)

| test_id | Class | Invariant | Expected evidence |
|---|---|---|---|
| FV-A2-020 | CB | valid USD operating intent | freezes; fingerprint set; eligible |
| FV-A2-021 | CB | non-USD rejected | service `InvalidArgumentException` **and** DB `CHECK` violation |
| FV-A2-022 | CB | zero/negative rejected | service rejection **and** DB `CHECK` violation |
| FV-A2-023 | CB | allocations must equal intent amount | under- and over-allocated intents refuse to freeze |
| FV-A2-024 | CB | **mixed operating/trust: complete but blocked** | completeness PASS; eligibility `trust_execution_disabled`; `open()` throws |
| FV-A2-025 | CB | frozen material mutation rejected | `LogicException`; fingerprint still matches; no new allocations |
| FV-A2-026 | CB | supersede creates new historical intent | original keeps amount + fingerprint, gains forward pointer; replacement is a new Draft |
| FV-A2-027 | CB | attempt transition matrix enforced | legal path succeeds; skipping submission and post-terminal moves refused |
| FV-A2-028 | CB | OUTCOME_UNKNOWN cannot generate a new charge | 2nd `open()` refused; 1 attempt, 1 command, original uuid + key retained |

### Trust (v1.4 §49)

| test_id | Class | Invariant | Expected evidence |
|---|---|---|---|
| FV-A2-030 | CB | trust execution remains disabled | existing `PaymentClassificationService` still blocks unconditionally, even with `payment_mode = operating_and_trust` |
| FV-A2-031 | CB | trust allocation cannot create an executable command | `TrustExecutionDisabledException`; 0 attempts, 0 commands, 0 outbox rows; a single trust cent blocks |
| FV-A2-032 | CB | no trust provider destination exists | command vocabulary is exactly `capture_payment`/`refund_payment`; DB `CHECK` refuses anything else |
| FV-A2-033 | CB | processor-fee path cannot debit trust principal | Pay journal recorder imports no Trust class; executable intent carries 0 trust cents |

### Accounting (v1.4 §50)

| test_id | Class | Invariant | Expected evidence |
|---|---|---|---|
| FV-A2-040 | CB | existing cash/cheque posting still correct | a cheque payment still debits Operating Cash (regression) |
| FV-A2-041 | CB | **card capture does not debit Operating Cash** | debit set excludes Operating Cash, includes Processor Clearing |
| FV-A2-042 | CB | clearing entry balances | debits == credits == gross; credit legs are the existing revenue accounts |
| FV-A2-043 | CB | Provider Settlement Receivable available | purpose resolvable on a configured firm |
| FV-A2-044 | CB | processor fee account reused | `ProcessorFees` reused, not re-created |
| FV-A2-045 | CB | duplicate capture posting blocked | replay returns the original entry; exactly 1 entry per attempt |
| FV-A2-046 | CB | legal revenue not duplicated | 3 postings → revenue credited once |
| FV-A2-047 | CB | trust ledger firewall regression | all 5 `trust_*` tables remain empty |

### Refund (v1.4 §51)

| test_id | Class | Invariant | Expected evidence |
|---|---|---|---|
| FV-A2-050 | CB | reservation succeeds | state `reserved`, capacity held |
| FV-A2-051 | CB | **two concurrent reservations cannot over-reserve** | 2 OS processes, both requesting the full amount → exactly 1 winner; held ≤ captured; 1 reservation row |
| FV-A2-052 | CB | exact DB locking mechanism demonstrated | `FOR UPDATE` on parent attempt, acquired **before** the sum is read and before the insert |
| FV-A2-053 | CB | OUTCOME_UNKNOWN keeps reservation active | held capacity unchanged; `reserved_at` preserved |
| FV-A2-054 | CB | unknown refund cannot create a second provider refund | resubmission refused; fresh reservation refused; `UNIQUE (provider_command_id)` blocks a second binding |
| FV-A2-055 | CB | duplicate/again-refund prevented | only a provably-unsent reservation may expire; unknown may never expire |

### Tenant security (v1.4 §52)

| test_id | Class | Invariant | Expected evidence |
|---|---|---|---|
| FV-A2-060 | CB | new tenant tables protected by RLS | contextless read = 0 rows; contextless insert refused |
| FV-A2-061 | CB | FORCE RLS verified | `relrowsecurity` and `relforcerowsecurity` true for all 6 tables; policy present; registered in the coverage registry |
| FV-A2-062 | CB | Firm A cannot reference Firm B PaymentIntent | cross-firm allocation and attempt both rejected by composite FK |
| FV-A2-063 | CB | Firm A cannot reference Firm B provider account | composite FK to `firm_integrations (firm_id, id)` rejects it (FV-A2-012) |
| FV-A2-064 | CB | Firm A cannot post a payment journal against Firm B | composite FK on `payment_attempt_id`; existing service still refuses foreign chart-of-accounts rows |
| FV-A2-065 | CB | worker mutation requires validated tenant context | contextless update affects 0 rows; row verified untouched |
| FV-A2-066 | CB | routing resolver cannot reach tenant financial data | see Ownership table |

### Deferred to later gates

| test_id | Gate | Class | Invariant | Why not now |
|---|---|---|---|---|
| FV-B-015 | B | CB | Finix UNKNOWN → canonical OUTCOME_UNKNOWN; existing attempt, command and idempotency identity retained; **no new charge** | Requires the Finix adapter and Sandbox, neither authorized before Gate B. The provider-independent half is already proved by FV-A2-028. |
| FV-A3-xxx | A3 | CB | Full fake-provider certification of the outbox → adapter → outcome loop | Gate A3 scope (v1.4 §15). |
| settlement / fee posting | later | CB | settlement evidence → `PROVIDER_SETTLEMENT_RECEIVABLE` + `PROCESSOR_FEES`; bank evidence → `OPERATING_CASH` | Posting a settlement before settlement evidence exists would invent an economic event. Accounts are in place; postings are not. |
