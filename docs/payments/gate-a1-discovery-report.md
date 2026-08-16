# FirmsVault Pay — Finix Sandbox POC #1

## GATE A1 DISCOVERY REPORT

**Authority:** Master Execution Prompt v1.4 FINAL · Architecture baseline FirmsVault Pay v3.1 (FROZEN/APPROVED)
**Scope executed:** GATE A1 ONLY — repository lineage, isolated worktree, payment discovery, accounting assessment.
**Date:** 2026-08-16
**No Finix code, no migrations, no staging changes, no credentials.**

---

## A. Git / Worktree Isolation

### A.1 Proven lineage

The repository lineage is **strictly linear** through the relevant branches. Verified with
`git merge-base --is-ancestor` and `git rev-list --left-right --count` (not assumed):

```
integration/ecs-and-tenant-foundation  4597c0d   (2026-07-13)
        ↓ ancestor of
main                                   14410eb   (2026-07-17)
        ↓ ancestor of
reconcile/myattorney-superadmin-onto-staging-safety  0d99b59  (2026-08-14)
        ↓ ancestor of
admin/myattorney-final-hardening       1a3458e   (2026-08-15 01:44)
        ↓ ancestor of
admin/final-reconciliation             bb52cdb   (2026-08-15 19:39)   ← SELECTED FINIX BASE
        ↓ ancestor of
release/staging-frankenphp-security-refresh  f98c1e9  (2026-08-15 21:37)
        ↓ ancestor of
release/production-readiness-closure   db1268a   (2026-08-16 00:52)   ← ACTIVE RELEASE WORK
```

### A.2 Required report fields

| Field | Value |
|---|---|
| Production Readiness branch | `release/production-readiness-closure` |
| Production Readiness commit | `db1268a05824d227847af4dc896a6fe4cab79020` |
| Production Readiness worktree | `/home/ubuntu/firmsbase-worktrees/production-readiness-closure` (clean) |
| **Selected Finix base branch** | `admin/final-reconciliation` |
| **Selected base commit** | `bb52cdb9cee1c2ba8360ec015c6193de63933ce2` |
| **Finix branch** | `feature/firmsvault-pay-finix-poc1` |
| **Finix branch starting commit** | `bb52cdb9cee1c2ba8360ec015c6193de63933ce2` |
| **Finix worktree** | `/home/ubuntu/firmsbase-worktrees/firmsvault-pay-finix-poc1` (clean) |
| Session's originally checked-out worktree | `/home/ubuntu/firmsbase-ecs-staging-validation` on `admin/myattorney-final-hardening` @ `1a3458e` — **not used as the base** |

### A.3 Why this base is correct

1. `admin/final-reconciliation` @ `bb52cdb` is the **last commit that is application reconciliation
   work rather than release-closure work**. It is the merge point where all seven admin control-plane
   branches (`core-superadmin-security`, `governance-console`, `integration-operations`,
   `billing-commercial`, `configuration-control-plane`, `support-access`, `operations-control-plane`)
   were reconciled into one coherent application state.
2. The only four commits separating it from the active release branch are **release/deployment
   concerns, not application/payment concerns**: FrankenPHP base-image refresh, base-digest guard
   re-pin, a Filament display-name fix for `ClientPortalUser`, and public-UUID input validation.
   None of them touch payment, billing, accounting, trust, provider, event, or RLS code.
3. It contains **all payment-relevant work**. Every payment/billing/trust/accounting/webhook branch
   was verified as an ancestor: `feature/phase-03-billing-foundation`,
   `feature/phase-06-commercial-billing-entitlements`, `feature/phase-12-accounting-expenses`,
   `feature/phase-13-trust-accounting-foundation`, `feature/section-39a3g-force-rls-invoices`,
   `feature/section-39a3h-force-rls-payments`, `feature/rls-remaining-60-integration`,
   `feature/phase-14-firm-webhooks-integrations`, `feature/phase-14b-webhook-event-wiring`,
   `fix/fvli-billing-durability-redesign`, `fix/fvli-release-candidate-remediation`,
   `admin/billing-commercial`, `feature/firmsvault-live-integrations`,
   `feature/integration-core-framework`.
4. **Four payment-relevant branches are not ancestors and were explicitly reviewed** rather than
   waved through: `feature/rls-trust-domain` (783bfce), `feature/rls-accounting-domain` (ca680e1),
   `feature/rls-matter-expenses` (210fab2), `feature/rls-webhook-domain` (7f1fcea). Each is exactly
   1 commit "ahead" but 389–420 commits behind. Every file each of them touches is **present in the
   base**, and blob-hash comparison of representative RLS migrations from all four
   (`trust_accounts`, `trust_ledger_entries`, `expenses`, `matter_expenses`, `webhook_events`)
   confirmed the base's copies are **byte-identical**. Their content reached the base through
   `feature/rls-remaining-60-integration`. **Nothing payment-relevant is stranded.**

### A.4 Proof Finix did not branch from active Production Readiness work

```
git merge-base --is-ancestor release/production-readiness-closure feature/firmsvault-pay-finix-poc1
  → FALSE   (PRC is NOT an ancestor of the Finix branch)

git rev-list --count feature/firmsvault-pay-finix-poc1..release/production-readiness-closure
  → 4       (the release branch holds 4 commits the Finix branch has never seen)

git rev-list --count release/production-readiness-closure..feature/firmsvault-pay-finix-poc1
  → 0       (the Finix branch contributes nothing to the release branch)
```

The Finix branch sits **behind** the release branch on shared reconciled history, never **on** it.
The two worktrees are physically separate directories with separate checkouts
(`/home/ubuntu/firmsbase-worktrees/firmsvault-pay-finix-poc1` vs
`/home/ubuntu/firmsbase-worktrees/production-readiness-closure`); the Production Readiness worktree
was read for status only and was not modified.

---

## B. Repository Inventory

Laravel/PHP application, PostgreSQL, Filament admin, ECS/Terraform infrastructure.

| Area | Scale | Location |
|---|---|---|
| Models | 253 | `app/Models`, `app/Integrations/Models` |
| Services | 381 | `app/Services`, `app/Integrations/Services` |
| Jobs | 15 + integration jobs | `app/Jobs`, `app/Integrations/Jobs` |
| Migrations | 537 | `database/migrations` |
| Provider/integration framework | full subsystem | `app/Integrations/**` |
| Infrastructure | Terraform, ECS | `infrastructure/ecs` |

Payment/financial code lives in **three structurally distinct layers**, which must not be confused:

1. **Tenant financial domain** (law firm ↔ its clients) — `Invoice`, `InvoiceLine`, `Payment`,
   `PaymentAllocation`, `PaymentReversal`, `InvoiceWriteOff`, `PaymentRequest`, `PaymentPlan`,
   `ManualPaymentRecord`, `PendingPaymentAllocation` (table `payment_pending_allocations`),
   `AccountingJournalEntry`, `AccountingPosting`, `ChartOfAccount`, `AccountingPeriod`, `Trust*`.
   **This is the layer FirmsVault Pay / Finix belongs to.**
2. **Platform commercial billing** (FirmsBase ↔ its law-firm customers) — `PlatformInvoice`,
   `PlatformPayment`, `PlatformPaymentAttempt`, `PlatformRefund`, `PlatformBillingEvent`,
   `BillingAccount`. Classified `Global`/EXEMPT from tenant RLS by design. **Out of scope for Finix.**
3. **Provider integration framework** — `app/Integrations/**` (Plaid, Google Workspace, Microsoft
   365, TestProvider). **This is the layer the v3.1 provider hierarchy maps onto.**

---

## C. Existing Payment Architecture

### C.1 Canonical tenant payment model

`app/Models/Payment.php` — table `payments`. Documented in-repo as "THE canonical payment table
(project rule: reusable by Phase 6 Stripe flows and Phase 13 trust accounting)".

Relevant columns: `firm_id`, `client_id`, `matter_id`, `invoice_id`,
`payment_plan_installment_id`, `amount_cents` (unsigned int), `currency` (default `'usd'`),
`payment_method`, `payment_classification`, `status`, `external_reference`, `idempotency_key`,
`rejection_reason`, `recorded_by`.

Idempotency already enforced in the database:

```sql
CREATE UNIQUE INDEX payments_one_per_firm_idempotency_key
  ON payments (firm_id, idempotency_key) WHERE idempotency_key IS NOT NULL;
```

### C.2 Payment status model (v3.1 comparison)

| Existing `PaymentStatus` | v3.1 minimal `PaymentAttempt` | Assessment |
|---|---|---|
| `initiated` | `CREATED` | approximate match |
| — | `SUBMITTED` | **MISSING** |
| `succeeded` | `CAPTURED` | approximate match |
| `failed` | `FAILED` | match |
| — | `DECLINED` | **MISSING** (folded into `failed`) |
| — | `OUTCOME_UNKNOWN` | **MISSING** on the money path |
| — | `CANCELLED` | **MISSING** |
| `pending`, `classified`, `blocked`, `refunded`, `partially_refunded`, `disputed`, `reversed` | — | broader lifecycle, no v3.1 equivalent |

`payments` is a **record of an outcome**, not a provider-attempt state machine. It has no lease,
no owner token, no send counter, no provider command identity. The v3.1 `PaymentAttempt` concept
is genuinely **MISSING** on the money path — but see D.2: an equivalent state machine already
exists for non-money provider calls and is the correct thing to extend.

### C.3 Existing provider abstraction for payments

`app/Services/Stripe/StripeGateway.php` — a **two-method, fake-only interface**
(`createPaymentIntent`, `createRefund`). Implementations: `FakeStripeGateway` and
`UnavailablePaymentGateway`. The container binding in `app/Providers/AppServiceProvider.php` is a
**fail-closed factory**: `PaymentGatewaySimulationPolicyService::isSimulationEnabled()` is the sole
source of truth (testing always simulated; local only on explicit opt-in; everything else never), and
outside simulation it resolves to `UnavailablePaymentGateway`, which throws
`PaymentProviderUnavailableException` rather than fabricating a success.

**No real payment provider call exists anywhere in the repository today.** No Stripe SDK, no LawPay,
no Finix, no Braintree, no Adyen, no Authorize.net.

`StripeGateway` is far below v3.1's requirements: no idempotency identity, no attempt lease, no
outcome-uncertain concept, no tenant provider account, no webhook contract. It is used by
`PlatformPaymentService`/`PlatformRefundService` (layer 2) and by `PaymentRequestCheckoutService`
(layer 1, payment links/QR).

### C.4 Entry-channel orchestration precedent

`app/Services/PaymentRequestCheckoutService.php` is the established pattern for "a payer pays
online", and it is explicitly documented as an **entry channel, never a second ledger**:

```
provider confirms payment (StripeGateway)
  → PaymentClassificationService        (trust vs operating decision)
  → ManualPaymentService                (canonical payment creation)
  → PaymentApplicationService           (invoice allocation)
  → OperatingJournalRecorderService     (double-entry posting)
```

The provider "never decides Trust vs Operating, earned vs unearned, invoice allocation, or ledger
postings — it only collects and confirms". A gateway-confirmed payment that cannot be routed into
the canonical domain is moved to `PendingReview` with a recorded `Failed` event for human
resolution — never silently dropped, never auto-retried.

**This is exactly the boundary FirmsVault Pay must occupy.** Finix is a new entry channel behind the
same wall, not a new financial domain.

---

## D. Existing Event / Idempotency / Audit Architecture

### D.1 Transactional outbox — `integration_outbox_events`

Columns include `firm_id`, `firm_integration_id`, `domain_event_id` (uuid), `event_type`,
`resource_type`, `resource_id`, `payload_json` (jsonb), `payload_hash`, `status`, `lock_token`,
`locked_at`, `attempts`, `max_attempts`, `next_attempt_at`, `last_error`, `completed_at`,
`dead_lettered_at`, `cancelled_at`.

Enforcement already present:

- `UNIQUE (firm_id, domain_event_id)` — dedupe
- composite FK `(firm_id, firm_integration_id) → firm_integrations (firm_id, id)` — tenant consistency
- `CHECK` constraint tying `status='processing'` to non-null `lock_token` + `locked_at`
- FORCE RLS enabled
- `App\Jobs\OutboxDispatchJob` claims via `FOR UPDATE SKIP LOCKED`, with stale-lock reclaim after 15
  minutes and dead-lettering

This is a **production-grade transactional outbox**. v3.1's `OutboxMessage` should EXTEND it.

### D.2 Durable provider command — `provider_operation_attempts`

The closest existing analogue to v3.1's `ProviderCommand`, and it is strong:

- `logical_operation_key` — **globally UNIQUE**, a deterministic hash of stable business inputs,
  never wall-clock. The at-most-once anchor as a real DB constraint.
- `owner_token` + `lease_expires_at` — single-winner send ownership
- `send_count` (per generation, invariant: **no row may ever exceed 1**), `total_send_count`
  (monotonic), `reclaim_count`
- `provider_request_reference`, `redacted_result_metadata`, `result_checksum`, `provider_outcome`,
  `billable_classification`
- `attempt_state` with `ProviderOperationAttemptState`:
  `claimed`, `attempt_started`, `provider_succeeded`, `provider_rejected`,
  **`provider_outcome_uncertain`**, `local_processing_failed`, `local_processing_complete`,
  `retry_allowed`, **`reconciliation_required`**

**The v3.1 `OUTCOME_UNKNOWN` distinction already exists here as a first-class state**, with the
correct semantics: `ProviderCallOutcomeNormalizer` documents that
`network_error`/`timeout`/`unknown` is **never assumed billable**, and `reconciliation_required`
routes to human/automated reconciliation rather than a retry that could double-charge.

**Deliberate design constraint (must be understood before extending):** this table has **no foreign
keys at all**, on purpose. `firm_id` and `firm_integration_id` are plain scalars. The migration
docblock records that a prior checkpoint (8.1) tried to make this durable via an FK-bearing table on
an independent connection and **deadlocked in production** — `PullSyncJob` holds `FOR UPDATE` on
`firm_integrations` across a provider call, and a cross-session INSERT whose composite FK references
that locked row must take `FOR KEY SHARE`, which is incompatible. Proven live via
`pg_stat_activity`/`pg_locks`. The compensating controls are: ownership validated **before** claiming
against real FK-backed rows, rows are operational evidence rather than a source of truth for money
owed, and a dangling scalar can only cause refusal or reconciliation — never authorize a call.

It is classified **Global/EXEMPT** from RLS for the same reason (the pre-claim probe must run before
firm context necessarily exists); tenant attribution is preserved via the scalar `firm_id`, and every
query in `ProviderOperationAttemptService` filters on it explicitly.

### D.3 Inbound provider events

| v3.1 concept | Existing implementation |
|---|---|
| `ProviderEventIngress` | `App\Integrations\Http\Controllers\InboundWebhookController` |
| signature verification | `InboundWebhookSignatureVerifier` (HMAC, ≤2 secret candidates, rotation overlap window) |
| receipt / replay dedupe | `integration_webhook_receipts` — `UNIQUE (routing_token_hash, body_hash)`, `body_hash`, `provider_event_id`, `verification_outcome`, `retention_deadline`, ack-consistency `CHECK` |
| canonical event | `integration_inbound_webhook_events` (FORCE RLS) |
| deferred processing | `DispatchPullSyncOnVerifiedWebhookEvent`, `DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent` listeners |

Anti-enumeration is already a documented discipline: the resolver collapses **every** non-usable case
(unknown provider, unknown token, disconnected connection, revoked-only credential) to an
indistinguishable empty/null result, and the controller must never branch its wire response on which
case occurred.

### D.4 Domain event engine — `domain_events`

`firm_id`, `event_type`, `subject_type`/`subject_id`, `correlation_id`, `causation_event_id`,
`causation_depth`, `payload_json`, `processing_status`, `lock_token`, `attempts`, `max_attempts`,
`next_attempt_at`, `dead_lettered_at`. FORCE RLS enabled. Claimed by
`DomainEventClaimService` via `FOR UPDATE SKIP LOCKED`.

### D.5 Idempotency inventory (consolidated)

| Mechanism | Location | Enforcement |
|---|---|---|
| Payment idempotency | `payments.idempotency_key` | partial UNIQUE `(firm_id, idempotency_key)` |
| Journal posting idempotency | `accounting_journal_entries.idempotency_key` | partial UNIQUE `(firm_id, idempotency_key)`; retry returns the original entry |
| Outbox dedupe | `integration_outbox_events` | UNIQUE `(firm_id, domain_event_id)` |
| Webhook replay dedupe | `integration_webhook_receipts` | UNIQUE `(routing_token_hash, body_hash)` |
| Provider command identity | `provider_operation_attempts.logical_operation_key` | global UNIQUE |
| Provider request dedupe | `ProviderRequestDeduplicationService`, `ProviderRequestLock` | application + lock |
| Coverage registry | `IdempotencyKeyCoverageMappingService` | governance registry |

### D.6 Audit

There is **no single generic audit table**. Audit is domain-specific and append-only-by-convention:
`security_events`, `import_audit_events`, `payment_classification_events`, `payment_request_events`,
`trust_approval_events`, `platform_billing_events`, plus recorder services
(`PlatformAdminAuditEventRecorder`, `FirmUserAuditEventRecorder`, `ApiRequestAuditService`,
`InboundWebhookAuditLogger`, `IntegrationRequeueAuditLogger`, `RetentionSweepAuditLogger`) governed by
`AuditPreservationPolicyService`.

---

## E. Billing ↔ FirmsVault Pay — Accounting Integration Decision Record

### E.1 Current Billing behavior (what the repository actually does)

- **Invoices** are created by `InvoiceDraftingService` into `invoices` / `invoice_lines`.
- **There is no Accounts Receivable posting at invoice issuance.** The `accounts_receivable`
  `ChartOfAccountPurpose` case exists in the enum, but no code posts to it.
- **Revenue is recognized at cash receipt, not at invoice issuance.** This is an explicit, documented
  design choice in `OperatingJournalRecorderService`: fees become `legal_fee_revenue` at the moment
  cash is actually received against a billed invoice (`InvoicePaymentApplied` /
  `TrustToOperatingTransfer`) — the same moment `PaymentClassificationService` and
  `PaymentApplicationService` already treat as authoritative.
- **Double entry is real.** `accounting_journal_entries` (header: `firm_id`, `entry_date`,
  `description`, `source_type`, `reverses_journal_entry_id`, `idempotency_key`, plus optional
  `payment_id` / `invoice_id` / `expense_id` / `trust_transfer_request_id` /
  `pending_payment_allocation_id`) + `accounting_postings` (lines: `chart_of_account_id`,
  `client_id`, `matter_id`, `debit_cents`, `credit_cents`, `memo`).
- **Posting is atomic post-or-block.** `OperatingJournalRecorderService` documents the "Accounting
  Integrity Hardening Pass": either the firm has never enabled the accounting module (documented
  NOT-APPLICABLE, returns null), or the posting succeeds atomically, or
  `AccountingSetupIncompleteException` rolls back the **entire** business transaction. There is never
  a committed business event with no accounting consequence.
- **Invoice balances** are reduced through `PaymentApplicationService` / `PaymentAllocation`; a
  fee/cost split is resolved by `resolveInvoiceRevenueAllocation()`, never re-derived by the journal.
- **Unresolved cash** has a real home: `payment_pending_allocations` +
  `unapplied_operating_funds_liability`, posted via `recordUnappliedFundsReceived()` /
  `recordUnappliedFundsResolved()`.
- **Refunds**: `OperatingPaymentRefundService` writes `payment_reversals`
  (`reversal_type=refund`) and posts a **new compensating entry** via `recordCashOut()` — it never
  mutates prior journal history. The one exception is a still-pending, never-recognized receipt, which
  genuinely is a correction and uses `AccountingJournalReversalService::reverse()`.
- **Write-offs / credits**: `InvoiceWriteOffService` → `invoice_write_offs`, posting to
  `write_off_bad_debt`.
- **Chargebacks**: `OperatingChargebackService` (operating) and `TrustChargebackService` (trust) —
  separate, never shared.
- **Period control**: `accounting_periods` + `AccountingPeriodCloseService`.
- **Integrity checking**: `AccountingIntegrityService` (read-only consistency checker).

### E.2 Current payment behavior

`ManualPaymentService::submit()` is the canonical creation path (staff-recorded and payment-link
alike). Everything happens inside **one** `TenantContextService::runWithFirmContext()` closure
wrapping a real `DB::transaction()`:

```
Payment row (status=initiated)
  → PaymentClassificationService::classify()  → Operating | Trust(blocked) | Blocked
  → PaymentClassificationEvent (append-only, for accepted AND blocked)
  → PaymentApplicationService (invoice allocation, fee/cost split)
  → OperatingJournalRecorderService (double-entry posting)
```

### E.3 Existing trust behavior

Trust is a **fully separate ledger**, never on the operating books. `TrustLedger` /
`TrustLedgerEntry` / `TrustBalance` / `MatterTrustBalance` are the sole source of truth for client
funds. Money becomes firm revenue only when `TrustTransferRequestService::apply()` converts it into
a real `Payment` against an approved invoice. A firewall test
(`FinancialEvidenceTrustLedgerFirewallTest`) restricts which files may read `TrustLedgerEntry` at
all, and `OperatingLedgerBankMatchingService` is structurally forbidden from importing any `Trust*`
class.

### E.4 Existing processor-fee behavior

**None.** `ChartOfAccountPurpose::ProcessorFees = 'processor_fees'` exists in the enum and is the
only occurrence of the concept in the entire application — **no code posts to it**. There is no
processor-fee model, no fee capture, no fee netting, no fee evidence, no settlement model.

Consequence: **no current code path deducts a processor fee from anything, trust or operating.**
The critical safety risk this gate was told to hunt for does not currently exist in the repository.

### E.5 Proposed responsibility boundary (proposal only — nothing implemented)

| Economic event | Owner | Rationale |
|---|---|---|
| Invoice / receivable creation | **BILLING** | Already owned; Pay must not create invoices. Note: today this produces **no** journal posting at all. |
| Successful payment (money captured at Finix) | **SHARED INTEGRATION EVENT** | Pay owns provider truth (attempt, command, capture evidence). Billing owns the canonical `Payment` + allocation + revenue posting, via the existing `ManualPaymentService` → `PaymentApplicationService` → `OperatingJournalRecorderService` chain. **Pay must never post `legal_fee_revenue` itself** — that would duplicate legal revenue. |
| Payment decline / failure | **FIRMSVAULT PAY** | No financial posting. Attempt-state + audit only. |
| Refund request | **FIRMSVAULT PAY** | Reservation/command identity only; no posting until the provider confirms. |
| Successful refund | **SHARED INTEGRATION EVENT** | Pay owns provider confirmation; Billing owns `payment_reversals` + the compensating posting through the existing `OperatingPaymentRefundService`. |
| Failed refund | **FIRMSVAULT PAY** | No financial posting; release the reservation, audit. |
| Provider settlement evidence | **FIRMSVAULT PAY** | New. Settlement ≠ bank cash. Moves `PROVIDER_SETTLEMENT_RECEIVABLE`, never revenue. |
| Processor fee evidence | **FIRMSVAULT PAY** | New. Posts to the existing `processor_fees` expense purpose, funded **only** from the operating side. |
| Fee correction / reversal | **FIRMSVAULT PAY** | New compensating posting; never mutate history (matches the established repository rule). |
| Future bank evidence | **SHARED INTEGRATION EVENT** | Plaid `FinancialEvidence*` already exists as read-only evidence; `OperatingLedgerBankMatchingService` already matches bank evidence to the operating journal and is deliberately stateless. |

### E.6 Accounting safety invariants — assessment

**"Successful payment does NOT duplicate legal revenue" — SUPPORTABLE.**
Revenue is recognized in exactly one place (`OperatingJournalRecorderService::recordFeeEarned()`),
under a deterministic idempotency key (`invoice_payment_applied:payment:{id}`) protected by a partial
unique index. As long as Finix routes through the existing `ManualPaymentService` chain rather than
posting its own revenue, duplication is structurally prevented by a database constraint.

**"Provider settlement does NOT equal bank cash" — NOT YET REPRESENTABLE.**
The intended chain requires accounts that do not exist:

```
PROCESSOR_CLEARING_OPERATING     ← MISSING from ChartOfAccountPurpose
        ↓
PROVIDER_SETTLEMENT_RECEIVABLE   ← MISSING from ChartOfAccountPurpose
        ↓
future actual bank evidence      ← EXISTS (Plaid FinancialEvidence*, read-only)
        ↓
OPERATING_CASH                   ← EXISTS (ChartOfAccountPurpose::OperatingCash)
```

Today, `recordInvoicePaymentApplied()` debits `operating_cash` directly at the moment of payment —
i.e. it currently **treats payment receipt as bank cash**. For manual/check/cash payments that is
defensible. For a card processor it is not: Finix capture is not bank cash. Two new account purposes
are required in Gate A2, and the Finix path must debit clearing rather than `operating_cash`.
`ChartOfAccountPurpose`'s own docblock notes that cases may exist before anything posts to them, so
adding purposes is an established, low-risk extension — but it is a **schema/enum change and is
therefore PROPOSED — NOT IMPLEMENTED**.

---

## F. Trust / IOLTA Findings

### F.1 Current trust execution state

**Trust/IOLTA payments are unconditionally blocked in the current codebase.**
`PaymentClassificationService::classify()` rejects **any** requested `TrustIoltaPayment`
classification regardless of `firm_settings.payment_mode`:

> "Trust/IOLTA deposits are blocked until the Phase 13 trust accounting foundation is accepted."

`PaymentMode::OperatingAndTrust` records only what a firm is eventually *allowed* to do; it unblocks
nothing today.

**The POC #1 requirement `trust_execution_mode = DISABLED` is therefore already the repository's
actual, enforced state.** No new kill switch is needed for Gate A1, and there is no executable Finix
route to trust/IOLTA because there is no executable route to trust/IOLTA at all through `Payment`.

### F.2 Trust activation gating (for future compatibility)

`TrustEligibilityService` requires **all five** conditions with no override:
`customer_type === LawFirm` (legal_specialist always blocked, checked first, fails closed);
`trust_iolta` entitlement enabled; `payment_mode === OperatingAndTrust`;
`trust_iolta_protection !== false`; and an approved `TrustModeActivationLinked` event linked to a
**two-person-approved** `HighRiskChangeRequest`. "No automatic trust-mode activation" and "no
one-person production activation" are recorded as project rules, not configuration.

Supporting services: `TrustConcurrencyLockService`, `TrustCrossMatterProtectionService`,
`TrustReconciliationService`, `TrustLedgerEntryReversalService` (append-only reversal),
`TrustPilotExitCriteriaService`, `TrustIoltaDisableAcknowledgmentService`,
`TrustJurisdictionReadinessService`.

### F.3 Trust processor-fee risk — CRITICAL FINDING: **NEGATIVE**

**No current behavior can cause trust principal → processor fee deduction.** Three independent
structural reasons:

1. No processor-fee deduction exists anywhere (E.4) — the concept is an unused enum case.
2. Trust payments cannot execute at all (F.1).
3. The trust ledger is firewalled from the operating journal; `TrustLedgerEntry` writes are
   restricted, and the operating-side bank matcher is forbidden from importing any `Trust*` class.

**No finding to escalate, and nothing to "fix".** This is recorded as a *preserved* invariant that
Gate A2 must not regress: when trust execution is eventually enabled, the Finix fee posting must
draw from the operating side only, and the `processor_fees` posting must never take a trust-side
credit. Recommended as a **CERTIFICATION_BLOCKING** firewall test in a later gate.

---

## G. Client Fee / Surcharge Findings

**No surcharge, convenience-fee, or client-payment-fee functionality exists.** A repository-wide
search for `surcharge`, `convenience_fee`, `processing_fee` across `app`, `database`, `config`, and
`resources` returned **zero matches**. There is no firm-dashboard fee setting, no fee calculator, no
card-network fee configuration, and no per-state fee rule engine.

**Jurisdiction/state policy mechanisms that do exist** (all advisory, none automated):

- `firm_settings.state_jurisdiction` — reference metadata.
- `TrustJurisdictionReadinessService` — a **static, checklist-only** service that "makes NO compliance
  claim, resolves NO jurisdiction-specific rule automatically, and gates NOTHING by itself". Its
  `REQUIRED_REVIEW_ITEMS` cover state-bar IOLTA registration, three-way reconciliation cadence,
  permitted-bank lists, signature-card requirements, interest-remittance participation, and retention
  periods. State-bar-specific rules are explicitly recorded as **out of scope and not implemented**.
- `SignatureEsignLegalReadinessService` — the same advisory-checklist pattern, and the template
  `TrustJurisdictionReadinessService` was modelled on.

**Assessment for the future fee/surcharge policy requirement:** the repository has an established
pattern for *advisory* jurisdiction checklists, but **no mechanism whatsoever** for
policy-*constrained* firm configuration. The v1.4 requirement — that a firm must not gain an
unrestricted ability to impose any fee merely because a dashboard setting exists — would need a
genuinely new three-way governed resolver (jurisdiction policy × card-network rule × FirmsVault
policy). Nothing was built in this gate.

---

## H. Tenant / RLS / FORCE RLS Findings

### H.1 Mechanism

Tenant isolation is PostgreSQL RLS keyed on the `app.current_firm_id` session GUC, established by
`App\Services\TenantContextService`. `set_config()`'s `is_local` argument is chosen **adaptively**:
`SET LOCAL` (transaction-scoped) whenever an explicit transaction is active, session-scoped
otherwise, with explicit save/restore of the previous value.

Additional narrow self-lookup GUCs exist for unauthenticated/portal paths — `app.current_user_id`,
`app.current_client_id`, `app.current_payment_request_uuid`,
`app.current_marketplace_intake_uuid`, `app.current_client_portal_invitation_token` — each activated
by a dedicated method that never touches `app.current_firm_id` and always clears in a `finally`.
`app.current_payment_request_uuid` is directly payment-relevant: it is how a public payer reaches
their own payment request without firm context.

Coverage is governed by a first-class registry, `RowLevelSecurityCoverageMappingService`
(`PREPARED_TABLES`, `MISSING_PREPARED_TABLES`, `EXEMPT_TABLES`, `FULL_TABLE_INVENTORY_EXTRA`,
`EXEMPT_TABLE_METADATA`), with `TenantOwnershipClassification` values including `InheritedTenant`
and `Global`. Adding a table without registering it is a governed, test-enforced act.

### H.2 FORCE RLS status of payment-relevant tables

| Table | FORCE RLS | Note |
|---|---|---|
| `payments` | ✅ | |
| `invoices` | ✅ | |
| `invoice_lines` | ⬜ registry `InheritedTenant` | `invoice_id → invoices.firm_id` |
| `payment_allocations` | ✅ | |
| `payment_reversals` | ✅ | |
| `payment_pending_allocations` | ✅ | |
| `invoice_write_offs` | ✅ | |
| `payment_requests` | ✅ | |
| `payment_plans` | ✅ | |
| `manual_payment_records` | ⬜ registry `InheritedTenant` | `payment_id → payments.firm_id` |
| `accounting_journal_entries` | ✅ | |
| `accounting_postings` | ✅ | |
| `chart_of_accounts` | ✅ | |
| `accounting_periods` | ✅ | |
| `trust_accounts`, `trust_ledgers`, `trust_ledger_entries` (+7 more) | ✅ | full trust domain |
| `domain_events` | ✅ | |
| `firm_integrations`, `integration_credentials` | ✅ | |
| `integration_outbox_events` | ✅ | |
| `integration_inbound_webhook_events` | ✅ | |
| `integration_external_mappings` | ✅ | |
| `provider_billable_call_reservations` | ✅ | |
| `integration_webhook_routing_index` | ❌ **deliberate** | Global/EXEMPT, documented (see I) |
| `integration_webhook_receipts` | ❌ **deliberate** | pre-tenant receipt, no firm-resolving column |
| `provider_operation_attempts` | ❌ **deliberate** | Global/EXEMPT, documented (see D.2) |
| `platform_*` (payments/invoices/refunds/attempts) | ❌ **by classification** | Platform-owned, `Global` — not tenant data |

**Payment-relevant tenant tables are essentially fully FORCE-RLS covered.** Every uncovered table is
either registry-classified `InheritedTenant` (isolation transitive through an RLS-protected parent)
or a reviewed, documented `Global`/EXEMPT pre-tenant table.

### H.3 Worker tenant context

Workers do **not** inherit context; they establish it explicitly. `OutboxDispatchJob` is dispatched
per-firm (`$this->firmId`), claims rows via `SKIP LOCKED`, and passes `firm_id` explicitly to every
handler; it never re-fetches a claimed row. `PullSyncJob`/`PushSyncJob` take `lockForUpdate()` on
`firm_integrations`. Queue driver is configurable (`QUEUE_CONNECTION`, default `database`, with SQS
and Redis available); failed jobs use `database-uuids`. A dead-letter surface exists
(`DeadLetterQueueResource`) plus `SweepIntegrationRetentionCommand`.

**Gap:** context is established per-job by convention, and there is no single enforced worker
tenant-context wrapper. Any Finix worker must follow the `OutboxDispatchJob` pattern explicitly.

---

## I. Provider Ownership Findings

### I.1 Does a `ProviderResourceLocator` equivalent exist? — **YES, in narrow form.**

`integration_webhook_routing_index` is a **working, security-reviewed, pre-tenant provider-resource →
tenant ownership index**:

```sql
CREATE TABLE integration_webhook_routing_index (
  id, firm_id, firm_integration_id, integration_provider_id,
  webhook_routing_token_hash varchar(64),
  UNIQUE (webhook_routing_token_hash),
  FOREIGN KEY (firm_id, firm_integration_id)
    REFERENCES firm_integrations (firm_id, id) ON DELETE CASCADE
);
```

It resolves an inbound routing identifier to exactly `{firm_id, firm_integration_id}` **before any
tenant context exists**, with:

- **no RLS** — deliberate and extensively documented, specifically to avoid a `SECURITY DEFINER`
  function (explicitly rejected in the frozen design) or a session-GUC-gated carve-out policy
  (explicitly rejected as an undisclosed deviation);
- **no secret material** — only a one-way sha256 hash; possession of the token never authorizes
  processing, since a valid HMAC signature is still separately required;
- a **composite FK** so a row cannot claim a `firm_integration` belonging to a different firm than its
  `firm_id` asserts — i.e. `Locator → Firm A` / underlying connection `→ Firm B` is **already
  structurally unrepresentable**;
- a **single writer** (`ProviderConnectionService::enableWebhookRouting()`/`disableWebhookRouting()`/
  `disconnect()`), in the same transaction that writes `firm_integrations.webhook_routing_token`, so
  plaintext and hash can never drift; the ingestion path only ever `SELECT`s;
- a **single reader** (`WebhookConnectionResolverService::resolveConnectionIdentity()`).

The docblock states it is "structurally the ONLY table in this entire design that carries a
firm-identifying pointer without RLS — a deliberate, reviewed, narrow exception, not a precedent for
any other table."

### I.2 The mapping layer — `IntegrationExternalMapping`

`integration_external_mappings` (FORCE RLS) is the existing `ProviderResourceMapping` equivalent:
it maps an already-owned external resource to internal business resources, with
`ExternalMappingConflictException` for conflicts. **It does not independently determine tenant
ownership** — matching v3.1's invariant.

The ownership → mapping split v3.1 requires therefore **already exists in practice**:

```
integration_webhook_routing_index   → SECURITY OWNERSHIP  (pre-tenant, no RLS, composite FK)
integration_external_mappings       → BUSINESS RELATIONSHIP (FORCE RLS, post-tenant)
```

### I.3 The structural gap Gate A2 must close

The locator is keyed on a **FirmsVault-issued routing token hash**, not on an **arbitrary external
provider resource id**. Providers whose webhooks carry no such token already required **per-provider
bespoke resolvers**, and there are now three parallel mechanisms:

| Resolver | Keyed on |
|---|---|
| `integration_webhook_routing_index` | FirmsVault CSPRNG routing token (hashed) |
| `GmailMailboxRoutingService` / `integration_gmail_mailbox_routes` | Google Pub/Sub `emailAddress` |
| `PlaidItemRoutingService` / `integration_plaid_item_routes` | Plaid `item_id` |

Finix webhooks reference **provider resource ids** (identity, merchant, transfer, dispute) and would
become a **fourth** bespoke resolver if the existing pattern is followed naively. The `Plaid`
resolver is the closest precedent and proves the shape works; but v3.1's generalized

```
(provider_platform_connection_id, provider_resource_type, provider_resource_id)
  → (firm_id, firm_provider_account_id)
```

is the correct consolidation. This is the **single most important Gate A2 design decision** and is
recorded in O.2.

### I.4 Provider hierarchy mapping

| v3.1 | Existing | Status |
|---|---|---|
| `ProviderPlatformConnection` | `IntegrationProvider` (`integration_providers`, seeded-only closed catalog, no RLS by design) | **EXTEND** |
| `FirmProviderAccount` | `FirmIntegration` (`firm_integrations`, FORCE RLS, composite-FK parent) | **EXTEND** |
| `ProviderResourceLocator` | `integration_webhook_routing_index` + 2 bespoke resolvers | **REFACTOR / EXTEND** |
| `ProviderResourceMapping` | `IntegrationExternalMapping` | **REUSE** |
| `ProviderCommand` | `ProviderOperationAttempt` | **EXTEND** |
| `OutboxMessage` | `IntegrationOutboxEvent` | **EXTEND** |
| `PaymentProviderAdapter` | `IntegrationProviderContract` + capability contracts (`SupportsWebhooksContract`, `SupportsOAuthContract`, `SupportsApiKeyContract`, …), `ProviderRegistry` | **EXTEND** (add a payment capability contract) |
| `CanonicalProviderEvent` | `IntegrationInboundWebhookEvent` | **EXTEND** |
| `ProviderEvidenceArtifact` | `integration_webhook_receipts` + `SanitizedPayloadReference` | **EXTEND** |
| `PaymentAttempt` (money) | — | **MISSING** |
| `Refund` (provider-executed) | `PaymentReversal` (domain-only) | **EXTEND** |
| Settlement | — | **MISSING** |
| Processor fee | `ChartOfAccountPurpose::ProcessorFees` (unused) | **MISSING** (account exists, nothing else) |

---

## J. Tenant Relational Integrity Matrix

**Convention:** 25 migrations use composite `(firm_id, id)` foreign keys. The established pattern is
`firm_integrations (firm_id, id)` referenced by `integration_*` children. It is **not** applied
uniformly across the older financial domain.

| Relationship | Tenant invariant | Existing DB enforcement | Existing app enforcement | Required future change | Reason |
|---|---|---|---|---|---|
| `integration_outbox_events → firm_integrations` | outbox row and connection share a firm | ✅ composite FK `(firm_id, firm_integration_id)` | ✅ | none | correct model to copy |
| `integration_webhook_routing_index → firm_integrations` | locator cannot claim another firm's connection | ✅ composite FK | ✅ single writer | none | the ownership invariant, already enforced |
| `accounting_journal_entries → payments` | journal entry and payment share a firm | ❌ plain FK `payment_id` | ✅ same-transaction firm context; RLS blocks reading a foreign payment | **composite FK** `(firm_id, payment_id) → payments (firm_id, id)` | a raw insert can name a foreign firm's `payment_id`; RLS prevents *reading* it but not *referencing* it |
| `accounting_journal_entries → invoices` | same | ❌ plain FK | ✅ | **composite FK** | same |
| `accounting_journal_entries → expenses` / `trust_transfer_requests` / `pending_payment_allocation` | same | ❌ plain FK | ✅ | **composite FK** | same |
| `accounting_postings → accounting_journal_entries` | posting and header share a firm | ❌ plain FK (both carry `firm_id`) | ✅ | **composite FK** | a posting could be attached to another firm's entry |
| `accounting_postings → chart_of_accounts` | posting uses its own firm's account | ❌ plain FK | ✅ | **composite FK** | a firm could post to another firm's ledger account |
| `payments → invoices` / `clients` / `matters` | payment and target share a firm | ❌ plain FK | ✅ + RLS | **composite FK** | same class of gap |
| `invoice_lines → invoices` | transitive isolation | ❌ plain FK; no own `firm_id` | ✅ registry `InheritedTenant` | none for A2 | established, registered pattern |
| `manual_payment_records → payments` | transitive isolation | ❌ plain FK; no own `firm_id` | ✅ registry `InheritedTenant` | none for A2 | established, registered pattern |
| `provider_operation_attempts → *` | attribution only | ❌ **no FKs at all, deliberate** | ✅ pre-claim ownership validation | **none — do not add FKs** | adding them re-creates the proven production deadlock |
| **NEW** `PaymentAttempt → PaymentIntent` | cross-firm attempt impossible | — | — | **composite FK + `UNIQUE (id, firm_id)` on parent** | v3.1 requirement; the FV example invariant |
| **NEW** `ProviderResourceMapping → ProviderResourceLocator` | mapping cannot outrank the locator | — | — | **FK to locator; mapping carries no independent ownership** | v3.1 §11 |

**Headline finding:** the newer `app/Integrations` domain enforces tenant-consistent composite FKs
properly; the older accounting/payment domain relies on application discipline plus RLS. RLS makes a
cross-firm reference **unreadable** but not **unwritable**, so a defect or a raw query could
represent `Firm A journal entry → Firm B payment`. This is a real, pre-existing gap — **not
introduced by Finix**, and **not fixed in Gate A1**.

---

## K. Concurrency / Locking Findings

### K.1 Existing conventions

| Mechanism | Usage | Representative sites |
|---|---|---|
| `SELECT … FOR UPDATE` | 22 files | `PaymentAllocationResolutionService`, `PaymentRequestCheckoutService`, `PlatformRefundService`, `TrustConcurrencyLockService`, `ProviderBillableCallPipeline`, `ProviderOperationAttemptService`, `PullSyncJob`, `PushSyncJob` |
| `FOR UPDATE SKIP LOCKED` | queue claiming | `DomainEventClaimService`, `AutomationActionExecutionClaimService`, `OutboxDispatchJob`, `RetentionSweepJob`, `SweepIntegrationRetentionCommand` |
| Lease + compare-and-set | at-most-once sends | `provider_operation_attempts.owner_token` / `lease_expires_at`; `markAttemptStarted()` CAS increments `send_count` |
| Partial unique indexes | idempotency | `payments`, `accounting_journal_entries`, `integration_credentials` (one Active per type) |
| `CHECK` constraints | state consistency | outbox processing-lock; webhook-receipt ack |
| Stale-lock reclaim | worker crash recovery | outbox 15-minute reclaim |
| **Advisory locks** | **none** | `pg_advisory_lock` appears nowhere |
| **`SERIALIZABLE` isolation** | **none** | never set explicitly |

### K.2 Proposed Gate A2 mechanisms (proposal only)

| Protected operation | Proposed mechanism | Rationale |
|---|---|---|
| `ProviderCommand` idempotency | global `UNIQUE` on the deterministic key | copies the proven `logical_operation_key` anchor |
| `ProviderResourceLocator` assignment | `UNIQUE (connection, resource_type, resource_id)` + insert-only conflict handling | exactly one winner; the loser is rejected, never merged (FV-A-039) |
| Locator ownership immutability | `CHECK` + trigger-free application rule + audit; no `UPDATE` path on ownership columns | v3.1 §11 historical immutability |
| Refund reservation | `SELECT … FOR UPDATE` on the parent payment + conditional update | matches `PaymentRequestCheckoutService` / `PlatformRefundService` precedent |
| Journal posting | existing partial `UNIQUE (firm_id, idempotency_key)` | already proven; extend, do not replace |
| Canonical event processing | `FOR UPDATE SKIP LOCKED` claim + `UNIQUE` on provider event identity | matches `DomainEventClaimService` |
| Settlement ingestion | `UNIQUE` on provider settlement identity + conditional update | dedupe re-delivered settlement files |
| Outbox dispatch | existing `SKIP LOCKED` + stale-lock reclaim | reuse unchanged |

**Recommendation:** do **not** introduce advisory locks or `SERIALIZABLE` isolation. The repository
has a consistent, proven vocabulary (row locks, `SKIP LOCKED`, unique constraints, conditional
updates, leases); adding a new concurrency primitive would be a novel failure mode in the highest-risk
domain.

---

## L. Secrets / Evidence Storage Findings

### L.1 Secrets

- **AWS Secrets Manager** is wired at the infrastructure layer (`infrastructure/ecs/modules/iam`,
  `infrastructure/ecs/environments/staging`) with task-role scoped access.
- **Provider credentials** are stored in `integration_credentials` (FORCE RLS), encrypted per-firm via
  `EmailBodyEncryptionService` + `EncryptionKeyService` with an `encryption_key_id`, and typed by
  `CredentialType` (including `WebhookSigningSecret`) with `IntegrationCredentialStatus`
  (`Active`/`Rotated`/`Revoked`) and a partial unique index enforcing exactly one Active per type.
- **Rotation** is first-class: a configurable overlap window (default 24h) lets a `Rotated` secret
  still verify inbound signatures.
- **Decryption is gated**: `IntegrationCredentialService::decryptForOperation()` requires `Active`
  status and takes an operation id + reason, and rejects token-shaped values in those labels
  (a 20+ character run drawn from `[A-Za-z0-9+/=_-]`) to prevent secret leakage through audit labels.
- `credential_environment_mode` exists on `integration_credentials`, and
  `ProviderEnvironmentResolver` separates sandbox from live — **directly relevant to Finix Sandbox**.

**v3.1 `ProviderPlatformConnection.credential_secret_reference` maps cleanly onto this**: a
platform-level (non-tenant) credential reference should point at Secrets Manager, while per-firm
provider credentials continue to use `integration_credentials`. **No Finix credential was created,
requested, or configured.**

### L.2 Evidence storage

- `integration_webhook_receipts` already stores **hash-based evidence**: `body_hash` (sha256),
  `routing_token_hash`, `provider_event_id`, `signature_version`, `verification_outcome`,
  `received_at`, `provider_timestamp`, `acknowledgment_status`, `retention_deadline`. It stores
  hashes and metadata, **not raw payload bodies**.
- **Redaction is a first-class discipline**: `SanitizedPayloadReference`,
  `SanitizedProviderHttpException`, `SanitizedHealthDiagnostic`, `SanitizedSyncFailureSummary`,
  `SanitizedUsageMetadataReference`; `provider_operation_attempts` stores only
  `redacted_result_metadata` + `result_checksum`, explicitly "never tokens, never raw banking
  payloads".
- **Object storage**: S3 disk configured in `config/filesystems.php`; document-side controls exist
  (`DocumentHashService`, `DocumentSecurityService`, virus scanning via `ClamAvVirusScanner`).
- **Retention/legal hold**: `RetentionPolicyService`, `AuditPreservationPolicyService`,
  `RetentionSweepJob`, `retention_deadline` columns.

**Gaps for `ProviderEvidenceArtifact`:** no object **versioning** or tamper-evident **immutability**
(e.g. S3 Object Lock) is configured, and there is no raw-payload evidence store. If Finix POC #1
requires retaining raw provider responses, that is a new capability — the existing posture is
deliberately hash-and-redact.

---

## M. FirmsVault Pay — Existing Capability Reuse & Gap Matrix

| Capability | Existing implementation | Location | Status | Architecture compatibility | Security implications | Gate A2 action |
|---|---|---|---|---|---|---|
| Billing / invoicing | `InvoiceDraftingService`, `invoices`, `invoice_lines` | `app/Services`, `database/migrations` | **REUSE** | compatible | FORCE RLS on `invoices` | none |
| Invoice / AR | AR account purpose exists, **never posted**; revenue at cash receipt | `ChartOfAccountPurpose`, `OperatingJournalRecorderService` | **EXTEND** | compatible; no AR today | none | decide whether Finix requires AR — probably not for POC |
| Payment domain | `Payment` (canonical), `ManualPaymentService`, `PaymentApplicationService` | `app/Models`, `app/Services` | **EXTEND** | compatible as the *domain* record | FORCE RLS; DB idempotency | route Finix through it; do not fork |
| Payment attempt state machine (money) | — | — | **MISSING** | v3.1 `PaymentAttempt` absent | must not double-charge | new `PaymentAttempt`, modelled on `provider_operation_attempts` |
| Refunds (domain) | `PaymentReversal`, `OperatingPaymentRefundService` | `app/Services` | **EXTEND** | compatible; compensating postings, never mutation | FORCE RLS | add provider-executed refund path |
| Refunds (provider) | — | — | **MISSING** | — | reservation/duplicate risk | reservation + command identity |
| Trust / IOLTA | full Phase 13 domain, execution **blocked** | `app/Services/Trust*` | **REUSE (untouched)** | compatible; POC needs DISABLED, already true | firewalled from operating | no Finix trust route |
| Ledger / double entry | `accounting_journal_entries` + `accounting_postings` | `database/migrations`, `AccountingJournalPostingService` | **REUSE** | compatible | FORCE RLS; idempotency index | reuse posting API unchanged |
| Chart of accounts | `ChartOfAccountPurpose` (11 purposes incl. `ProcessorFees`) | `app/Enums` | **EXTEND** | needs 2 new purposes | none | add clearing + settlement receivable |
| Provider abstraction (payments) | `StripeGateway` (2 methods, fake-only) | `app/Services/Stripe` | **REPLACE** | **incompatible** with v3.1 | fail-closed today — good | replace with a capability contract in the Integrations framework |
| Provider abstraction (general) | `IntegrationProviderContract` + capability contracts, `ProviderRegistry` | `app/Integrations/Contracts`, `Core` | **EXTEND** | compatible | tenant-safe policy services | add a payment capability contract |
| Provider ownership (locator) | `integration_webhook_routing_index` (+2 bespoke resolvers) | `app/Integrations` | **REFACTOR / EXTEND** | conceptually compatible; keyed on token not resource id | reviewed no-RLS exception; composite FK prevents split ownership | generalize to `(connection, resource_type, resource_id)` |
| Provider mapping | `IntegrationExternalMapping` | `app/Integrations/Models` | **REUSE** | compatible; already non-authoritative for ownership | FORCE RLS | bind to the locator |
| Idempotency | 6 distinct DB-enforced mechanisms | repo-wide | **REUSE** | compatible | strong | reuse patterns verbatim |
| Provider command | `ProviderOperationAttempt` (+ lease, CAS, `send_count ≤ 1`) | `app/Integrations` | **EXTEND** | strongly compatible | FK-free by proven necessity | extend for money operations |
| Outbox | `IntegrationOutboxEvent` + `OutboxDispatchJob` | `app/Integrations`, `app/Jobs` | **REUSE / EXTEND** | compatible | FORCE RLS, composite FK, CHECK | add payment command handlers |
| Inbound events | `InboundWebhookController`, `InboundWebhookSignatureVerifier`, `integration_webhook_receipts` | `app/Integrations` | **EXTEND** | compatible | HMAC + rotation + anti-enumeration | add Finix verification in Gate B |
| Canonical events | `IntegrationInboundWebhookEvent`; `domain_events` | `app/Integrations`, `app/Models` | **EXTEND** | compatible | FORCE RLS | add payment canonical events |
| Event dedupe | `UNIQUE (routing_token_hash, body_hash)`; `UNIQUE (firm_id, domain_event_id)` | migrations | **REUSE** | compatible | strong | add provider event identity uniqueness |
| Audit | domain-specific append-only tables + recorder services | repo-wide | **EXTEND** | compatible; no generic table | `AuditPreservationPolicyService` | add payment-lifecycle audit events |
| RLS | GUC-based, registry-governed | `TenantContextService`, `RowLevelSecurityCoverageMappingService` | **REUSE** | compatible | mature | register every new table |
| FORCE RLS | ~all payment tenant tables covered | migrations | **REUSE** | compatible | mature | FORCE RLS on new tenant tables |
| Worker tenant context | per-job explicit (`OutboxDispatchJob` pattern) | `app/Jobs` | **REUSE** | compatible | by convention, not enforced | follow the pattern explicitly |
| Concurrency | row locks, `SKIP LOCKED`, leases, unique constraints | repo-wide | **REUSE** | compatible | proven | no new primitives |
| Secrets | Secrets Manager + encrypted `integration_credentials` + rotation + env mode | infra + `app/Integrations` | **REUSE** | compatible | strong | map `credential_secret_reference` |
| Evidence storage | hash + redaction + retention | `app/Integrations` | **EXTEND** | partially compatible | no raw-payload store, no immutability | decide evidence scope |
| Settlement | — | — | **MISSING** | — | settlement ≠ bank cash | model in Gate A2 |
| Processor fees | unused enum case only | `ChartOfAccountPurpose` | **MISSING** | — | trust-principal safety | model in Gate A2, operating-funded only |
| Reconciliation | `AccountingIntegrityService`, `OperatingLedgerBankMatchingService`, `TrustReconciliationService`, `ProviderInvoiceReconciliationService` | `app/Services` | **EXTEND** | compatible; bank matching already stateless | never auto-reclassify money | add settlement reconciliation |
| Surcharge / client fees | — | — | **MISSING** | — | jurisdiction risk | out of scope for POC #1 |

---

## N. Gate A2 Proposed Schema / Invariant Plan

**PROPOSED — NOT IMPLEMENTED. No migration was created. No schema was changed.**

| # | Invariant | Likely mechanism |
|---|---|---|
| 1 | `ProviderCommand` idempotency — one logical operation, one command | global `UNIQUE` on a deterministic key (copy `provider_operation_attempts.logical_operation_key`) |
| 2 | `ProviderResourceLocator` uniqueness | `UNIQUE (provider_platform_connection_id, provider_resource_type, provider_resource_id)` |
| 3 | Locator ownership immutability | ownership columns insert-only; no application `UPDATE` path; `CHECK` + audit event on any attempted change |
| 4 | Concurrent conflicting ownership assignment | insert-only + unique violation → explicit rejection (never upsert/merge). Exactly one winner (FV-A-039) |
| 5 | Locator/connection tenant consistency | composite `FOREIGN KEY (firm_id, firm_provider_account_id) → firm_integrations (firm_id, id)` — proven precedent |
| 6 | `ProviderResourceMapping` depends on Locator | `FOREIGN KEY` to the locator; mapping carries **no** independent ownership columns |
| 7 | Tenant-consistent financial FKs | `UNIQUE (id, firm_id)` on parents + composite FKs on `accounting_journal_entries`, `accounting_postings`, `payments` (see J) |
| 8 | `JournalEntry` posting uniqueness | reuse the existing partial `UNIQUE (firm_id, idempotency_key)` — extend, do not replace |
| 9 | Event dedupe | reuse `UNIQUE (firm_id, domain_event_id)` |
| 10 | Canonical event dedupe | `UNIQUE (provider_platform_connection_id, provider_event_id)` |
| 11 | Refund reservation locking | `SELECT … FOR UPDATE` on the parent payment + conditional update + `UNIQUE` reservation key |
| 12 | Settlement identity | `UNIQUE` on provider settlement id per connection; conditional update on re-delivery |
| 13 | USD-only | `CHECK (currency = 'USD')` on new Pay tables. **Note the existing conflict:** `payments.currency` defaults to lowercase `'usd'` and has no `CHECK`. Case must be reconciled explicitly, not assumed |
| 14 | `PaymentAttempt` ↔ `ProviderCommand` 1:1 | `UNIQUE (provider_command_id)` on the attempt |
| 15 | `send_count ≤ 1` per generation | `CHECK (send_count <= 1)` — copy the existing at-most-once assertion |
| 16 | New tenant tables carry FORCE RLS | prepare + FORCE RLS migration per the established convention, **plus** registration in `RowLevelSecurityCoverageMappingService` |
| 17 | New Global/EXEMPT tables justified | `EXEMPT_TABLE_METADATA` entry + a "WHY THIS TABLE HAS NO RLS" migration docblock, matching the routing-index precedent |
| 18 | Amount safety | `amount_cents` as `bigint` with `CHECK (amount_cents > 0)`. Existing `payments.amount_cents` is `unsignedInteger` (~$21.4M ceiling) — a deliberate divergence to flag, not silently mirror |

---

## O. Architecture Deviations / Conflicts vs v3.1

### O.1 Naming collision — v3.1 names vs entrenched repository names (**material, must be resolved**)

v3.1 specifies `ProviderPlatformConnection` and `FirmProviderAccount`. The repository already has
`IntegrationProvider` and `FirmIntegration` occupying **exactly** those roles, with FORCE RLS,
composite-FK children, a seeded closed catalog, credential lifecycle, kill switches, health, and
usage metering. Creating v3.1-named tables alongside them would create **two provider-connection
authorities** — precisely the "second payment subsystem" §32 forbids.

**Recommendation:** treat the v3.1 names as **role names, not table names**; extend
`IntegrationProvider`/`FirmIntegration` and record the mapping. Requires an explicit architecture
ruling.

### O.2 `ProviderResourceLocator` is not a clean greenfield addition (**material**)

Per I.3, three parallel pre-tenant resolvers already exist. Three options:

- **(a) Fourth bespoke resolver** — follows precedent, contradicts v3.1's "sole security authority",
  worsens fragmentation.
- **(b) New generalized locator for Finix only** — satisfies v3.1 for Pay, leaves three legacy
  resolvers as *de facto* additional ownership authorities. **A second ownership authority is
  exactly what v3.1 §11 forbids** — though scoped to different providers, so not representable as a
  conflict for the same resource.
- **(c) Generalize and migrate all four** — the only option that truly makes the locator the sole
  authority; largest blast radius, touches a security-reviewed frozen design, and would collide with
  the Production Readiness release.

**Recommendation: (b) for POC #1**, with (c) recorded as required future consolidation and the
scoping constraint explicitly disclosed. This deviation is **disclosed, not resolved** — it needs an
architecture decision before Gate A2 implementation.

### O.3 `OUTCOME_UNKNOWN` exists, but not on the money path (**minor**)

`ProviderOperationAttemptState::ProviderOutcomeUncertain` + `ReconciliationRequired` already carry
the correct semantics for provider *service* calls. `PaymentStatus` has no equivalent — a
Finix-uncertain capture has nowhere correct to land today. **Extend, don't invent.**

### O.4 Payment receipt is currently posted as bank cash (**material for accounting**)

`recordInvoicePaymentApplied()` debits `operating_cash` immediately. Correct for cash/check; wrong
for a card processor. The v3.1 clearing → settlement-receivable → bank-cash chain requires two new
account purposes (E.6).

### O.5 `StripeGateway` is structurally inadequate (**not a conflict**)

Too narrow for v3.1, but it is a fake-only stub with a fail-closed binding and no production
callers making real calls. Replacing it is a clean act, not a migration off an entrenched system.
Its **fail-closed factory pattern should be preserved verbatim** for Finix.

### O.6 `provider_operation_attempts` has no foreign keys (**accepted deviation, do not "fix"**)

v3.1 implies tenant-consistent FKs throughout. This table cannot have them: a proven production
deadlock (`FOR UPDATE` vs `FOR KEY SHARE` across sessions) forced their removal. Any `ProviderCommand`
built on this table inherits the constraint. **Documented, compensated, and must not be reverted.**

### O.7 Currency representation mismatch (**minor**)

`payments.currency` defaults to lowercase `'usd'` with no `CHECK`. v3.1's USD-only constraint implies
a canonical form. Must be reconciled explicitly.

---

## P. Files Changed

| Change | Detail |
|---|---|
| New branch | `feature/firmsvault-pay-finix-poc1` from `bb52cdb` |
| New worktree | `/home/ubuntu/firmsbase-worktrees/firmsvault-pay-finix-poc1` |
| New file | `docs/payments/gate-a1-discovery-report.md` (this document) |

**No application code was modified. No migration was created. No schema, RLS policy, role, secret,
config, or infrastructure file was touched. The Production Readiness worktree was read-only.**

---

## Q. Tests Run

**None executed.** Gate A1 is repository-local discovery; the suite requires a live PostgreSQL
instance with RLS roles, and running it would neither validate nor inform any Gate A1 conclusion.
All findings above are derived from migration DDL, model/service source, and enum definitions —
i.e. from the authoritative declarations themselves rather than from runtime behavior.

Relevant existing test surfaces identified for later gates:
`tests/Feature/Security/RlsForceRollout/**`, `tests/Feature/TenantIsolation/**`,
`tests/Feature/Governance/DataModelContract/**`, `tests/Feature/Webhooks/**`,
`tests/Feature/Accounting/**`, `tests/Feature/Trust/**`, and the
`FinancialEvidenceTrustLedgerFirewallTest` firewall.

---

## Gate A Acceptance Test Registry (initiated)

| test_id | gate | classification | invariant | provider_dependency | required_fixture | expected evidence | allowed result |
|---|---|---|---|---|---|---|---|
| **FV-A-038** | A | CERTIFICATION_BLOCKING | `ProviderResourceLocator` is the sole authoritative provider-resource → tenant ownership source; `ProviderResourceMapping` cannot conflict with it | none (provider-independent) | two firms, two provider accounts, one provider resource id | attempt to create a mapping whose implied firm differs from the locator's firm is rejected; no row exists where `Locator → Firm A` and `Mapping → Firm B` | rejection only; no ambiguous state persisted |
| **FV-A-039** | A | CERTIFICATION_BLOCKING | Concurrent assignment of the same provider resource to different `FirmProviderAccount`s: exactly one succeeds | none | two concurrent transactions, same `(connection, resource_type, resource_id)`, different firms | one commit succeeds; the other fails on unique violation and is surfaced as an explicit conflict; exactly one locator row exists afterward | exactly one success; conflicting assignment rejected; never merged or upserted |
| **FV-B-015** | B | CERTIFICATION_BLOCKING | Finix UNKNOWN → canonical `OUTCOME_UNKNOWN`, original `PaymentAttempt` / `ProviderCommand` / idempotency identity all retained, **no new charge** | Finix Sandbox (approved fixtures only) | a Sandbox operation whose outcome is indeterminate (timeout / network failure) | attempt remains the same row; command key unchanged; `send_count` never exceeds 1; recovery performs a lookup, never a second charge | `OUTCOME_UNKNOWN` recorded and reconciled; a second charge is a hard failure |

Additional entries proposed for later registration (not yet formalized): trust-principal
processor-fee firewall; settlement ≠ bank cash; revenue non-duplication under payment retry.

---

## R. Final Decision

```
READY FOR GATE A2
```

**Justification.** Every Gate A1 success condition is met: the stable base is proven rather than
assumed, Finix work is physically isolated, Production Readiness is untouched, and the existing
payment, event, idempotency, audit, tenant-isolation, secrets, and evidence infrastructure is
inventoried against Architecture v3.1.

**No stop condition is triggered.** In particular: the base was identified confidently; Finix did not
branch from active release work; existing Billing behavior was determined precisely; the existing
payment subsystem is *narrow*, not *incompatible*; **trust processor fees are not deducted from trust
principal anywhere** (no processor-fee mechanism exists at all, and trust execution is
unconditionally blocked); RLS/FORCE RLS needs no weakening — v3.1's locator maps onto an existing,
security-reviewed no-RLS precedent with an established justification convention; no second payment
subsystem is required; and no credential, staging change, or real transaction was needed.

**Three deviations require an explicit architecture ruling before Gate A2 implementation begins**
(they are disclosed here, not silently resolved):

1. **O.1** — v3.1 provider-hierarchy names vs the entrenched `IntegrationProvider`/`FirmIntegration`
   tables. Recommendation: treat v3.1 names as roles and extend the existing tables.
2. **O.2** — `ProviderResourceLocator` cannot become the *sole* ownership authority without either
   accepting three legacy per-provider resolvers alongside it or refactoring a frozen,
   security-reviewed design. Recommendation: option (b), scoped to Finix, with consolidation
   recorded as required future work.
3. **O.4/E.6** — the operating journal currently treats payment receipt as bank cash. Two new
   `ChartOfAccountPurpose` cases are required before a card processor can post correctly.

**END OF GATE A1 REPORT.**
