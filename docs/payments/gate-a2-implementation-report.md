# FIRMSVAULT PAY — GATE A2 IMPLEMENTATION REPORT

**Finix Sandbox POC #1** · Master Execution Prompt v1.4 FINAL · Architecture v3.1 FROZEN
Branch `feature/firmsvault-pay-finix-poc1` · Worktree
`/home/ubuntu/firmsbase-worktrees/firmsvault-pay-finix-poc1` · Base `admin/final-reconciliation`
@ `bb52cdb`

**Provider-independent throughout. No Finix SDK, adapter, API call, webhook verifier,
tokenization page, credential or migration exists anywhere in this work.**

Companion documents:
- [Gate A2 Existing Infrastructure Compatibility Decision](gate-a2-compatibility-decision.md)
- [Gate A2 Acceptance Test Registry & Database Invariant Matrix](gate-a2-acceptance-test-registry.md)

---

## A. Compatibility decisions

Full reasoning in the compatibility decision document. Rulings:

| Component | Role | Ruling |
|---|---|---|
| `IntegrationProvider` | `ProviderPlatformConnection` | **REUSE AS-IS** |
| `FirmIntegration` | `FirmProviderAccount` | **REUSE AS-IS** |
| `provider_operation_attempts` | at-most-once send gate | **REUSE AS-IS, unmodified** |
| `provider_operation_attempts` | `ProviderCommand` | **INCOMPATIBLE for that role** → new `provider_commands` **WRAPS** it |
| `integration_outbox_events` | `OutboxMessage` | **REUSE AS-IS** |
| `integration_webhook_routing_index` | `ProviderResourceLocator` | **EXTEND** (second addressing mode) |
| `IntegrationExternalMapping` | `ProviderResourceMapping` | **REUSE AS-IS, untouched** |
| ledger (`accounting_*`) | ledger | **REUSE** + one additive source-link column |
| `security_events` + recorders | audit | **EXTEND** |
| `domain_events` | event engine | **REUSE**, no change required |

No duplicate provider-account subsystem, no second payment ledger, no second outbox, no second
command engine, no second audit system. `FV-A2-001` asserts the absence of
`provider_platform_connections` / `firm_provider_accounts` / `provider_resource_locators` /
`provider_resource_mappings` tables.

---

## B. Files changed

### Modified (7 — all additive)

| File | Change |
|---|---|
| `app/Enums/ChartOfAccountPurpose.php` | +2 cases: `ProcessorClearingOperating`, `ProviderSettlementReceivable` |
| `app/Enums/AccountingJournalSourceType.php` | +1 case: `ProviderPaymentCaptured` |
| `app/Models/AccountingJournalEntry.php` | +1 fillable: `payment_attempt_id` |
| `app/Services/AccountingJournalPostingService.php` | passes `payment_attempt_id` through from `$sourceRefs`; every existing caller unaffected |
| `app/Services/RowLevelSecurityCoverageMappingService.php` | registers the 6 new tenant tables as prepared |
| `app/Integrations/Models/IntegrationWebhookRoutingIndex.php` | mode-B fields + ownership immutability guards |
| `app/Integrations/Services/ProviderConnectionService.php` | **3 mass-deletes narrowed** to `->whereNull('provider_resource_id')` — the only behavioral edit to existing integration code |

### Added — enums (6)
`PaymentIntentStatus`, `PaymentDestinationClass`, `PaymentAttemptState`, `PaymentRefundState`,
`ProviderCommandStatus`, `ProviderCommandType`

### Added — models (6)
`PaymentIntent`, `PaymentIntentAllocation`, `ProviderCommand`, `PaymentAttempt`, `PaymentRefund`,
`ProviderEvidenceArtifact`

### Added — services (6, `app/Services/Pay/`)
`PaymentIntentService`, `ProviderCommandService`, `PaymentAttemptService`,
`RefundReservationService`, `ProviderResourceOwnershipService`,
`ProviderPaymentJournalRecorderService`, plus `PayAuditRecorder`

### Added — exceptions (5, `app/Exceptions/Pay/`)
`IdempotencyConflictException`, `TrustExecutionDisabledException`,
`PaymentIntentNotExecutableException`, `RefundCapacityExceededException`,
`ProviderResourceOwnershipConflictException`

### Added — factories (5), migrations (14), tests (8 classes), docs (3)

---

## C. Migrations

All 14 state why existing schema was insufficient, the architecture role served, tenant ownership,
RLS behavior, FK strategy and rollback implications, per v1.4 §54.

| Migration | Purpose |
|---|---|
| `..._100001_add_provider_resource_ownership_to_integration_webhook_routing_index_table` | Adds mode-B (provider-resource) addressing to the **existing** locator. Partial unique indexes for both modes; addressing-mode `CHECK`; ownership-status `CHECK`. `down()` **refuses to run** if ownership rows exist. |
| `..._100002/100003` | `payment_intents` + RLS/FORCE RLS |
| `..._100004/100005` | `payment_intent_allocations` + RLS/FORCE RLS |
| `..._100006/100007` | `provider_commands` + RLS/FORCE RLS |
| `..._100008/100009` | `payment_attempts` + RLS/FORCE RLS |
| `..._100010/100011` | `payment_refunds` + RLS/FORCE RLS |
| `..._100012` | `accounting_journal_entries.payment_attempt_id` with a **composite** FK |
| `..._100013/100014` | `provider_evidence_artifacts` + RLS/FORCE RLS |

No RLS was weakened. No existing policy was modified. No worker bypass was introduced.

---

## D. Architecture role mapping

```
v3.1 role                        implementation
──────────────────────────────────────────────────────────────────────
ProviderPlatformConnection   →   IntegrationProvider   (integration_providers)   REUSED
FirmProviderAccount          →   FirmIntegration       (firm_integrations)       REUSED
ProviderResourceLocator      →   integration_webhook_routing_index (mode B)      EXTENDED
ProviderResourceMapping      →   IntegrationExternalMapping                      REUSED
                                 + provider_commands / payment_attempts /
                                   payment_refunds as business mappings
ProviderCommand              →   provider_commands                               NEW (wraps gate)
   at-most-once send gate    →   provider_operation_attempts                     REUSED UNCHANGED
OutboxMessage                →   integration_outbox_events                       REUSED
PaymentIntent                →   payment_intents                                 NEW
PaymentAllocation (intent)   →   payment_intent_allocations                      NEW
PaymentAttempt               →   payment_attempts                                NEW
Refund                       →   payment_refunds                                 NEW
ProviderEvidenceArtifact     →   provider_evidence_artifacts                     NEW
CanonicalProviderEvent       →   integration_inbound_webhook_events              REUSED (untouched)
PaymentProviderAdapter       →   (Gate A3 / B)                                   NOT BUILT
```

**No parallel `provider_platform_connections` / `firm_provider_accounts` tables were created.**
Architecture names describe responsibilities, not Laravel table names (v1.4 §4).

---

## E. ProviderCommand decision

**Ruling: EXTEND by WRAPPING.** `provider_operation_attempts` is reused *unchanged* as the durable
at-most-once send gate; `provider_commands` is the new tenant-owned economic instruction.

The incompatibility is structural, not cosmetic:

> The gate writes on the independent `pgsql_audit` connection, in autocommit, with **no foreign
> keys**, deliberately so its evidence **survives a rollback of the caller's transaction** — a
> design forced by a *proven production deadlock* when Checkpoint 8.1 tried the FK-bearing variant.
> v1.4 §14 requires the ProviderCommand to **commit atomically with** the financial domain
> transaction. Those two requirements contradict each other on one row.

Binding: `ProviderCommand::logicalOperationKey()` = `fvpay:<command_uuid>` — deterministic, derived
from the immutable envelope, no wall-clock. One instruction → one gate row → one send permission.

**At-most-once vs safe retry.** The existing `send_count <= 1` invariant was neither weakened nor
adopted blindly; all four v1.4 §10 failure windows were analysed against the engine's real
transitions:

| Window | Outcome |
|---|---|
| crash before HTTP call | `claimed` + `send_count = 0` **proves** nothing was sent → sweeper → `retry_allowed`. Command not lost. |
| crash after call, before recording | `attempt_started` → lease lapse → `provider_outcome_uncertain` → `reconciliation_required`. No second charge. |
| HTTP timeout | same path; timeouts are never assumed billable. No second charge. |
| provider succeeded, local work failed | success recorded **before** local processing; resume completes local work **without** re-calling the provider. |

The only automated route back to sendable requires positive proof no billable work occurred;
everything else needs an audited operator resolution. Correct trade: an unnecessary reconciliation
costs human time, an unnecessary retry costs a client's money.

**Immutable envelope vs mutable execution metadata** (§12) is enforced by a model guard over
`ProviderCommand::ENVELOPE_FIELDS`; commands can never be deleted.

**Idempotency** (§13) is enforced by the database: `UNIQUE (firm_id, idempotency_key)`. The service
inserts first and lets the index arbitrate — it never pre-checks with a SELECT and trusts the gap.
Same key + same canonical payload (key-order-insensitive) returns the same command; same key +
different payload raises `IdempotencyConflictException` with **no provider execution** and a durable
audit record. It relies on none of controller validation, queue uniqueness, or provider-side
idempotency.

---

## F. Provider ownership implementation

One table, one unique index, one resolver, one writer.

```
external provider resource
        ↓
integration_webhook_routing_index      ← SOLE AUTHORITATIVE TENANT OWNERSHIP
        ↓
firm_id + FirmIntegration
        ↓
business-resource mapping (ProviderCommand / PaymentAttempt / PaymentRefund)
        ↑ may associate, may NEVER assign or override ownership
```

- **Uniqueness** — partial `UNIQUE (integration_provider_id, provider_resource_type,
  provider_resource_id)`.
- **Immutability** — the index deliberately **does not filter on `ownership_status`**, so
  `ACTIVE → INACTIVE` is allowed while `Firm A → Firm B` is impossible, including via
  deactivate-then-reclaim. Historical financial ownership stays provable.
- **No split ownership** — the pre-existing composite FK to `firm_integrations (firm_id, id)`
  already makes `Locator → Firm A` / connection `→ Firm B` unrepresentable.
- **Bounded pre-tenant read** — `resolveOwner()` returns only
  `{firmId, firmIntegrationId, integrationProviderId, providerKey}`, grants no access to payments,
  clients, matters, journals or refunds, and collapses every non-resolvable case to `null`.

**FV-A-038** — PASS. One authoritative owner; a second owner is refused **by the database**;
re-establishing identical ownership is idempotent.

**FV-A-039** — PASS. Genuine two-process concurrency via `pcntl_fork()` (following this
repository's existing `PlatformAdminRecoveryCodeRaceTest` precedent), two separate PostgreSQL
sessions racing the same unique index: **exactly one winner, one rejection, exactly one surviving
active row, no ambiguous or dual ownership state.**

---

## G. PaymentIntent / Allocation / Attempt

**The §18 separation, implemented as two independent queries:**

| Question | Method | Mixed $3,000 operating / $7,000 trust on a $10,000 intent |
|---|---|---|
| Allocation completeness | `allocationsAreComplete()` / asserted at `freeze()` | **PASS** |
| Execution eligibility | `executionEligibility()` | **BLOCKED** — `trust_execution_disabled` |

Both are true simultaneously; that is the contradiction §18 ordered fixed (`FV-A2-024`).

**Freeze/supersede** — material fields immutable after freeze, guarded in the model and
fingerprinted (`material_fingerprint`, sha256 over the material set) so drift is detectable even if
a row were mutated outside Eloquent. A change is expressed by **superseding**: the original keeps
its amount and fingerprint forever and gains a forward pointer; the replacement is a new Draft.

### PaymentAttempt transition matrix (v1.4 §22 — exactly the 7 authorized states)

| From \ To | CREATED | SUBMITTED | CAPTURED | DECLINED | FAILED | OUTCOME_UNKNOWN | CANCELLED |
|---|---|---|---|---|---|---|---|
| **CREATED** | — | ✅ | ✗ | ✗ | ✅ | ✗ | ✅ |
| **SUBMITTED** | ✗ | — | ✅ | ✅ | ✅ | ✅ | ✗ |
| **CAPTURED** | ✗ | ✗ | — | ✗ | ✗ | ✗ | ✗ |
| **DECLINED** | ✗ | ✗ | ✗ | — | ✗ | ✗ | ✗ |
| **FAILED** | ✗ | ✗ | ✗ | ✗ | — | ✗ | ✗ |
| **OUTCOME_UNKNOWN** | ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ |
| **CANCELLED** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | — |

`AUTHORIZED`, `REQUIRES_ACTION`, `PROCESSOR_ACCEPTED`, `VOIDED`, `EXPIRED` were **not** introduced —
no current repository behavior requires them and POC #1 is capture-only.

**OUTCOME_UNKNOWN has no outgoing transitions**, and `PaymentAttemptService` refuses to open a new
attempt for an intent holding one. So an undetermined outcome can never become a second charge; the
original attempt, ProviderCommand and idempotency identity are all retained (`FV-A2-028`).

**Atomicity (§14)** — attempt + command + outbox row commit in one transaction. No provider network
call exists anywhere in this code path.

---

## H. Refund core

### Locking mechanism (v1.4 §25/§26) — stated exactly

```sql
BEGIN
  SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE;          -- (1) serialize reservers
  SELECT COALESCE(SUM(amount_cents),0) FROM payment_refunds        -- (2) read held capacity
    WHERE payment_attempt_id = ? AND state IN (<capacity-holding>);
  -- refuse if requested > captured - held
  INSERT INTO payment_refunds (...);                               -- (3) reserve
COMMIT
```

(1) is the mechanism: the lock is taken on the parent attempt **before** the balance is read, so a
second worker blocks until the first commits and then observes a sum that already includes it. This
is explicitly **not** the forbidden "SELECT balance → PHP compares → INSERT" pattern.

"Held capacity" has exactly one definition —
`PaymentRefundState::holdsRefundableCapacity()` — shared by the service and the tests, so they
cannot drift. It counts `Reserved`, `ProviderPending`, `OutcomeUnknown` and `Succeeded`.

### Refund transition matrix (v1.4 §27)

| From \ To | RESERVED | PROVIDER_PENDING | OUTCOME_UNKNOWN | SUCCEEDED | PROVIDER_FAILED | RESERVATION_EXPIRED | CANCELLED |
|---|---|---|---|---|---|---|---|
| **REQUESTED** | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✅ |
| **RESERVED** | — | ✅ | ✗ | ✗ | ✗ | ✅ | ✅ |
| **PROVIDER_PENDING** | ✗ | — | ✅ | ✅ | ✅ | ✗ | ✗ |
| **OUTCOME_UNKNOWN** | ✗ | ✗ | — | ✗ | ✗ | ✗ | ✗ |
| terminal states | ✗ | ✗ | ✗ | — | — | — | — |

**§28 — OUTCOME_UNKNOWN keeps the reservation held.** `resolve()` never clears `reserved_at`, the
state still holds capacity, resubmission is refused by the matrix, a fresh reservation for the same
money is refused by the capacity check, and `UNIQUE (provider_command_id)` prevents a second
provider refund command binding. `expireIfUnsent()` is legal **only** from `Reserved`.

**FV-A2-051** — PASS under genuine two-process concurrency: two OS processes each requesting the
full captured amount → exactly one winner, held ≤ captured, exactly one reservation row.

---

## I. Accounting implementation

### Posting matrix

| Event | Debit | Credit | Implemented |
|---|---|---|---|
| Cash / cheque / bank transfer payment applied *(existing, unchanged)* | `OPERATING_CASH` | `LEGAL_FEE_REVENUE` (+ `COST_REIMBURSEMENT_REVENUE`) | ✅ untouched |
| **Provider card capture** | **`PROCESSOR_CLEARING_OPERATING`** | `LEGAL_FEE_REVENUE` (+ `COST_REIMBURSEMENT_REVENUE`) | ✅ **new** |
| Provider settlement evidence | `PROVIDER_SETTLEMENT_RECEIVABLE` + `PROCESSOR_FEES` | `PROCESSOR_CLEARING_OPERATING` | ⬜ accounts in place, posting deferred |
| Actual bank evidence | `OPERATING_CASH` | `PROVIDER_SETTLEMENT_RECEIVABLE` | ⬜ deferred (later gate) |

Settlement and bank postings are deliberately **not** implemented: posting a settlement entry before
settlement evidence exists would invent an economic event. The accounts and the chain are in place
so later gates need no redesign.

### Proof that card capture ≠ Operating Cash

`FV-A2-041` reads the actual posting rows and asserts the debited-account set **excludes** the
firm's Operating Cash account and **includes** its Processor Clearing account.
`FV-A2-040` is the twin regression: a cheque payment through the untouched
`ManualPaymentService` still debits Operating Cash. Together they prove the provider path was added
*alongside* existing behavior, not in place of it (v1.4 §32).

### Basis preserved (§29)

**No Accounts Receivable was introduced.** The repository recognizes revenue at cash receipt and
posts no AR at invoice issuance; that basis is unchanged. The capture credits the **same** revenue
accounts at the **same** moment, so legal revenue is recognized exactly once
(`FV-A2-046`: three replayed postings → revenue credited once).

### No second ledger (§33)

Every posting goes through the existing `AccountingJournalPostingService::post()`, inheriting
balanced-journal validation, same-firm account checks, the closed-period guard and the partial
unique index on `(firm_id, idempotency_key)`. Capture idempotency key:
`provider_payment_captured:payment_attempt:<id>`.

---

## J. Trust / IOLTA proof

`trust_execution_mode = DISABLED`, structurally:

1. **Existing protection preserved, not renamed** — `PaymentClassificationService` still blocks
   every `TrustIoltaPayment` unconditionally, even for a firm configured
   `payment_mode = operating_and_trust` (`FV-A2-030`).
2. **New, independent, earlier refusal** — `PaymentAttemptService::open()` throws
   `TrustExecutionDisabledException` for **any** trust-destined allocation, even one cent. Zero
   attempts, zero commands, zero outbox rows are created (`FV-A2-031`).
3. **No trust destination exists** — the command vocabulary is exactly
   `capture_payment` / `refund_payment`, and a DB `CHECK` refuses anything else (`FV-A2-032`).
4. **Processor fees cannot touch trust principal** (`FV-A2-033`) — the Pay journal recorder imports
   no `Trust*` class (structural firewall, matching `OperatingLedgerBankMatchingService`'s
   precedent); the only value that can reach a capture is Operating-destined; the eventual fee leg
   is drawn from an operating clearing balance that only ever received Operating value.
5. **Trust ledger firewall regression** — all five `trust_*` tables remain empty after a full
   capture-and-post cycle (`FV-A2-047`).

**No surcharge / convenience-fee / firm-fee-percentage functionality was built** (v1.4 §21).

---

## K. Tenant Relational Integrity Matrix

| Relationship | Tenant invariant | Database enforcement | Application enforcement | RLS interaction | Exception | Reason |
|---|---|---|---|---|---|---|
| `payment_intent_allocations → payment_intents` | same firm | ✅ composite FK `(payment_intent_id, firm_id)` | service runs in firm context | both FORCE RLS | — | FV-A2-062 |
| `payment_attempts → payment_intents` | same firm | ✅ composite FK | ✅ | both FORCE RLS | — | FV-A2-062 |
| `payment_attempts → provider_commands` | same firm, 1:1 | ✅ composite FK + `UNIQUE (provider_command_id)` | write-once guard | both FORCE RLS | — | FV-A2-028 |
| `payment_attempts → firm_integrations` | same firm | ✅ composite FK `(firm_id, firm_integration_id)` | ✅ | both FORCE RLS | — | FV-A2-063 |
| `payment_refunds → payment_attempts` | same firm | ✅ composite FK | ✅ | both FORCE RLS | — | §35 |
| `payment_refunds → provider_commands` | same firm, 1:1 | ✅ composite FK + `UNIQUE` | ✅ | both FORCE RLS | — | §28 |
| `payment_refunds → firm_integrations` | same firm | ✅ composite FK | ✅ | both FORCE RLS | — | §35 |
| `provider_commands → payment_intents` | same firm | ✅ composite FK | ✅ | both FORCE RLS | — | §35 |
| `provider_commands → firm_integrations` | same firm | ✅ composite FK | ✅ | both FORCE RLS | — | FV-A2-012 |
| `provider_evidence_artifacts → provider_commands` / `firm_integrations` | same firm | ✅ composite FKs | ✅ | both FORCE RLS | — | §42 |
| `accounting_journal_entries → payment_attempts` | same firm | ✅ **composite FK** (unlike its plain-FK siblings) | ✅ | both FORCE RLS | — | FV-A2-064 |
| `integration_webhook_routing_index → firm_integrations` | ownership cannot split | ✅ pre-existing composite FK | single writer | locator has **no RLS** (documented, pre-existing) | ✅ disclosed | v1.4 §39 |
| `payment_intents → invoices` / `clients` / `matters` | same firm | ⚠️ plain FK (legacy parents) | ✅ + RLS | RLS hides foreign rows | ✅ disclosed | v1.4 §55 forbids broad legacy refactor; no *new* cross-firm path introduced |
| `accounting_postings → chart_of_accounts` | same firm | ⚠️ plain FK (pre-existing) | ✅ existing service refuses foreign/inactive accounts | RLS hides foreign rows | ✅ pre-existing (Gate A1 finding J) | Not introduced by Gate A2; `FV-A2-064` proves the application refusal still holds |

**Gate A2 introduced no new cross-firm-reference vulnerability.** Every new relationship on the Pay
path carries database-enforced tenant consistency.

---

## L. Database Invariant Matrix

See the [acceptance test registry](gate-a2-acceptance-test-registry.md), section A — 25 invariants
with table, database mechanism, application mechanism, failure behavior and acceptance test, plus an
explicit table of the three invariants that are **not** DB-enforced and exactly why (zero-trigger
convention; `REVOKE` does not bind the table owner; cross-row aggregates cannot be row `CHECK`s).

---

## M. RLS / FORCE RLS evidence

All six new tenant tables carry `ENABLE ROW LEVEL SECURITY`, a
`firm_id = current_setting('app.current_firm_id')` policy with matching `USING` and `WITH CHECK`,
and `FORCE ROW LEVEL SECURITY`. Verified directly from `pg_class` and `pg_policies`
(`FV-A2-060/061`), and each is registered in `RowLevelSecurityCoverageMappingService`.

- No policy was weakened, and none uses a permissive fallback.
- No `BYPASSRLS` role, no `SECURITY DEFINER` function, no session-GUC carve-out was introduced.
- `provider_operation_attempts` retains its pre-existing Global/EXEMPT posture, asserted unchanged.
- `integration_webhook_routing_index` retains its documented no-RLS posture; mode B adds no secret
  material and no new tenant-data reachability.

---

## N. Worker isolation evidence

- Every tenant financial mutation runs inside `TenantContextService::runWithFirmContext()`.
- A contextless mutation affects **0 rows** and the target is verifiably untouched (`FV-A2-065`).
- No worker bypass was created for asynchronous payment work.
- The one pre-tenant service, `ProviderResourceOwnershipService::resolveOwner()`, is bounded to
  routing identity and proven to grant no access to tenant financial tables (`FV-A2-066`).
- `PayAuditRecorder` establishes context from the firm it is given rather than requiring a
  pre-tenant caller to hold it.

---

## O. Acceptance Test Registry

See the [full registry](gate-a2-acceptance-test-registry.md), section B — every entry carries
test id, gate, classification, invariant, enforcement mechanism, provider dependency, fixture,
expected evidence and allowed result. All Gate A2 entries are **CERTIFICATION_BLOCKING**.

---

## Q. Architecture deviations

1. **ProviderCommand is two objects, not one.** v3.1 implies a single `ProviderCommand`. The
   at-most-once gate's rollback-survival requirement and §14's atomic-commit requirement cannot
   coexist on one row (a production deadlock forced the gate's FK-free, independent-connection
   design). Resolved by layering, with a deterministic key binding them. **Disclosed, not silently
   forked.**
2. **`ProviderResourceLocator` is an extension of an existing table, not a new one.** Authorized by
   v1.4 §5. Its no-RLS posture is inherited and documented rather than newly created.
3. **Three legacy per-provider resolvers still exist** (`integration_gmail_mailbox_routes`,
   `integration_plaid_item_routes`, and the token mode). They are *de facto* additional ownership
   paths for **other** providers. For the FirmsVault Pay path the new mode-B index is the sole
   authority; consolidating the legacy three remains **required future work** (carried over from
   Gate A1 finding O.2, unchanged).
4. **Ownership in-place immutability is application-enforced.** Zero-trigger convention; column
   `REVOKE` does not bind the table owner. The realistic reassignment route *is* DB-blocked.
5. **Currency case divergence.** New tables use uppercase `'USD'` with a `CHECK`; legacy
   `payments.currency` defaults to lowercase `'usd'` with no check. The two are never mixed; legacy
   was not rewritten (§55).
6. **`amount_cents` widened to `bigint`** on new tables, versus `payments`' unsigned integer
   (~$21.4M ceiling). Deliberate; legacy unchanged.

---

## R. Known limitations

1. Settlement and processor-fee **postings** are not implemented (accounts and chain are). Later gate.
2. No provider adapter, no outbox worker handler for the Pay event type — Gate A3.
3. Evidence artifacts are indexed but nothing writes them yet; no S3 Object Lock / object-versioning
   immutability (carried from Gate A1 finding L).
4. `provider_evidence_artifacts` holds only firm-attributed evidence. Unresolved ingress stays in
   `integration_webhook_receipts` — see the design correction in section “Design corrections” below.
5. Legacy accounting FK gaps (`accounting_postings → chart_of_accounts` and friends) remain
   application-enforced. Pre-existing; explicitly out of scope per §55.
6. Refund reservation expiry has no sweeper job yet; `expireIfUnsent()` exists and is safe.

### Design corrections made during implementation (reported, not hidden)

| # | What was wrong | How the system revealed it | Correction |
|---|---|---|---|
| 1 | `provider_evidence_artifacts.firm_id` was nullable to hold *unresolved* evidence | The insert was **impossible**: FORCE RLS + `WITH CHECK (firm_id = current firm)` means a NULL-firm row can never be inserted by any role | Made `firm_id` NOT NULL. Unresolved ingress stays in the Global/EXEMPT `integration_webhook_receipts`, which already exists for exactly that purpose — avoiding a duplicate subsystem (§32) |
| 2 | Refusal audits were erased by the rollback that followed them | An idempotency-conflict audit row vanished with its rolled-back transaction | Refusal events now write on the independent `pgsql_audit` connection, reusing `TimelineEventRecorder`'s established mechanism, and never mask the domain exception |
| 3 | A unique violation poisoned the caller's transaction (`25P02`) | The idempotent-reuse path could not run after catching the violation | Insert attempts wrapped in a nested `DB::transaction()` = SAVEPOINT, so a rejected insert unwinds only itself |
| 4 | `ProviderConnectionService`'s three routing-index mass deletes would have destroyed ownership rows | Identified by reading the call sites before shipping | Narrowed to `->whereNull('provider_resource_id')` — the minimum necessary safe change (§55) |
| 5 | `PaymentAttempt.provider_command_id` was never-writable, blocking its own creation path | Test failure on `open()` | Made **write-once** (null → value legal once; re-binding never) |
