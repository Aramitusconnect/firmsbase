# FIRMSVAULT PAY — GATE A3 CERTIFICATION REPORT

**Finix Sandbox POC #1** · Master Execution Prompt v1.4 FINAL · Architecture v3.1 FROZEN
Gate A1 PASS · Gate A2 PASS (GitHub run 31998632962 @ `179cc7f`) · **Gate A3 scope only.**

**Provider-independent throughout: no Finix SDK, API call, credential, webhook signing,
tokenization, settlement parsing, Finix-specific field or migration exists anywhere in this work.**

## A. Candidate revision

```
Branch: feature/firmsvault-pay-finix-poc1
Base:   179cc7f92ccf4e94d3a461c22ce16e6cde746a19  (Gate A2 certified)
Commit: <filled at certification — see §L>
```

## B. Provider contract

`App\Services\Pay\Contracts\PaymentProviderAdapter` — six methods, deliberately no more:

```
createCardPayment(ProviderPaymentRequest): ProviderResult
getPaymentOutcome(ProviderPaymentRequest): ProviderResult      ← authoritative, repeatable, side-effect-free
refundPayment(ProviderPaymentRequest): ProviderResult
getRefundOutcome(ProviderPaymentRequest): ProviderResult
getSettlementEvidence(ref): {gross_cents, net_cents, fees[], provider_metadata}
getFeeEvidence(ref): ProviderFeeEvidence[]
```

**Canonical input** (`ProviderPaymentRequest`, built ONLY from the immutable ProviderCommand
envelope + environment): command uuid, logical operation key, firm id, FirmIntegration id
(FirmProviderAccount role), IntegrationProvider id (ProviderPlatformConnection role), amount,
USD, operation, opaque method-token fixture, parent provider reference (refunds), correlation
id, environment. No provider-native object ever crosses the boundary (v1.4 §6).

**Canonical result** (`ProviderResult`): command uuid, nullable provider resource reference,
canonical outcome, nullable amount, currency, occurred_at, nullable evidence reference, and
**opaque** `provider_metadata` that core never inspects for financial decisions (§7).

**Canonical outcomes** (`ProviderOutcome`, §8): `SUCCEEDED, DECLINED, FAILED, OUTCOME_UNKNOWN,
CANCELLED` + the adapter-protocol signal `DUPLICATE_REQUIRES_LOOKUP` (§19), which
`isEconomicOutcome()` excludes from ever being persisted onto an attempt/refund.

**Why the existing `StripeGateway` was not reused**: 2-method fake-only stub with no command
identity, no idempotency semantics, no lookup operation, no unknown-outcome concept — it
structurally cannot express the v3.1 recovery model. It remains untouched for its existing
platform/checkout callers.

Fail-closed binding mirrors the existing precedent exactly: `FakePaymentProviderAdapter`
only under `PaymentGatewaySimulationPolicyService::isSimulationEnabled()`; the throwing
`UnavailablePaymentProviderAdapter` everywhere else.

## C. FakePaymentProviderAdapter — deterministic scenarios

Scenario selection flows through the REAL canonical payload (method token / refund reason) —
no global mutable state, no randomness, no network, no wall-clock dependence (§47):

| Token | Behavior |
|---|---|
| `fake:success` | SUCCEEDED with provider resource |
| `fake:decline` | DECLINED (resource recorded declined) |
| `fake:fail` | definitive FAILED |
| `fake:timeout-success` | provider PROCESSES, then caller times out; lookup → SUCCEEDED |
| `fake:timeout-fail` | provider refuses, caller times out; lookup → FAILED |
| `fake:timeout-unknown` | caller times out; lookup itself STILL_UNKNOWN (§17) |
| `fake:duplicate-lookup` | provider answers DUPLICATE_REQUIRES_LOOKUP (§19) |
| `fake:connection-mismatch` | ProviderConnectionMismatchException (§28) |
| `fake:environment-mismatch` | ProviderEnvironmentMismatchException (§29) |
| *(any second create for a held logical key)* | DUPLICATE_REQUIRES_LOOKUP — provider-side idempotency |

Behavioral realism (§10): every timeout scenario records the provider-side resource FIRST, then
throws — dispatch and economic outcome are genuinely separate facts, so "UNKNOWN → later
SUCCEEDED without a second charge" is real behavior. Coverage scenarios FEE_DEBIT/FEE_CREDIT
via `getFeeEvidence()` (one debit `processing`, one credit with NULL category), DUPLICATE_EVENT /
OUT_OF_ORDER_EVENT / UNMAPPED_EVENT via `FakeProviderEvent` fixtures into the ingestion service.
Nothing beyond Gate A3's needs was built (§48): no onboarding, checkout, ACH, disputes,
terminals, or pricing engine.

## D. ProviderCommand / idempotency results

| Case | Mechanism | Test | Result |
|---|---|---|---|
| same key + same payload | DB `UNIQUE (firm_id, idempotency_key)` + hash equality → same logical command | FV-A3-011 | PASS |
| same key + different payload | `IdempotencyConflictException`; **0 adapter calls, 0 provider resources, 0 attempts, 0 postings**, audited | FV-A3-012/013 | PASS |
| duplicate command delivery | claim gate → `AlreadyComplete`; adapter called exactly once; `send_count = 1` asserted from the gate row | FV-A3-009/023 | PASS |
| provider duplicate-idempotency | `DUPLICATE_REQUIRES_LOOKUP` → exactly one lookup → ORIGINAL transaction reconciled CAPTURED; never a failure, never a new charge | FV-A3-010 | PASS |
| worker retry after timeout | claim → `ReconciliationRequired` ⇒ `requires_recovery`; **no re-send** (`paymentCalls` still 1, `send_count` still 1), original uuid/key retained | FV-A3-014 | PASS |

The Gate A2 at-most-once gate (`provider_operation_attempts`) is reused **unchanged** — zero
edits to `app/Integrations/Billing`. §18 satisfied without weakening anything.

## E. Payment outcome recovery

| Path | Behavior proven | Test |
|---|---|---|
| UNKNOWN → SUCCESS | existing attempt → CAPTURED; exactly one posting, one ownership row, one journal; second recovery = `ALREADY_RESOLVED` | FV-A3-006 |
| UNKNOWN → FAILURE | existing attempt → FAILED; no success posting; no new charge command | FV-A3-007 |
| UNKNOWN → STILL UNKNOWN | attempt stays UNKNOWN; `reconciliation_required` stays true; retriable; **never a second charge** | FV-A3-008 |

The recovery exit from OUTCOME_UNKNOWN is the ONE sanctioned path
(`ProviderOutcomeApplierService`, guarded direct write under FOR UPDATE, authoritative result
only), exactly mirroring the existing gate where `resolveReconciliation()` is the single exit
from `reconciliation_required`. The gate row settles through its OWN sanctioned sequence
(`uncertain → reconciliation_required → resolveReconciliation(LocalProcessingComplete)`) —
`RetryAllowed` is deliberately never produced by automation, preserving the A2 security review.

## F. Payment race evidence (genuine OS-process concurrency, pcntl_fork)

All four run two real processes with separate PostgreSQL connections; certification asserts
POST-CONDITIONS (one terminal state, one journal, one ownership row), independent of which
interleaving occurred — deterministic despite scheduling nondeterminism (§47):

| Race | Test | Result |
|---|---|---|
| synchronous response vs provider event | FV-A3-020 (fork + sequential shape) | PASS |
| recovery lookup vs provider event | FV-A3-021 (fork) | PASS |
| duplicate event workers | FV-A3-022 (fork) | PASS |
| duplicate outbox delivery | FV-A3-023 (deterministic double delivery — the guard is the single-statement `send_count` CAS, exercised identically by sequential redelivery) | PASS |

Mechanism: every path converges on ONE applier whose safety is `SELECT … FOR UPDATE` +
terminal-state no-op, with the journal's partial UNIQUE and the ownership partial UNIQUE as
database backstops.

## G. Event evidence

| Behavior | Mechanism | Test | Result |
|---|---|---|---|
| duplicate event | DB `UNIQUE (firm_integration_id, provider_key, provider_event_id)` on the REUSED `integration_inbound_webhook_events` | FV-A3-030 | PASS |
| out-of-order event | DEFERRED (canonical row retained, status Verified, `deferred_dependency_missing`) — never FAILED, never guessed | FV-A3-031 | PASS |
| deferred later succeeds | same event re-ingested → PROCESSED exactly once; third delivery → DUPLICATE | FV-A3-032 | PASS |
| unmapped resource | UNRESOLVED: **no canonical row, no guessed firm, no attempt/refund/journal mutation**; repeatable without wearing down | FV-A3-033 | PASS |
| wrong provider connection | CONNECTION_MISMATCH, fail closed, zero mutation, audited | FV-A3-034 | PASS |
| wrong environment | ENVIRONMENT_MISMATCH, fail closed, zero mutation, audited | FV-A3-035 | PASS |
| ownership authority | `integration_webhook_routing_index` resolves 1 resource → exactly 1 (firm, account); no fake-provider ownership table exists | FV-A3-036 | PASS |

Deferral vs quarantine (§25/§26): both are the same restricted, retryable, fail-closed state —
an event whose locator never resolves simply never leaves it. Design note recorded in
`ProviderEventIngestionService`'s docblock.

## H. Refund evidence

| Behavior | Test | Result |
|---|---|---|
| success end-to-end: reserve → command+outbox atomic → dispatch → SUCCEEDED → one refund posting, reservation resolved (held as permanent consumption) | FV-A3-040 | PASS |
| definitive failure: PROVIDER_FAILED, capacity fully released, no posting | FV-A3-041 | PASS |
| timeout → OUTCOME_UNKNOWN, reservation REMAINS ACTIVE, no second refund command, fresh reservation of the held money refused | FV-A3-042/043 | PASS |
| unknown → success exactly once (idempotent re-recovery proven) | FV-A3-044 | PASS |
| unknown → failure releases safely, no posting | FV-A3-045 | PASS |
| unknown stays unknown, hold retained | (companion test) | PASS |
| duplicate refund delivery: one adapter call, one posting, `send_count = 1` | FV-A3-046 | PASS |
| refund recovery vs refund event race (fork): one result, one journal, one resolution | FV-A3-047 | PASS |

## I. Trust / fee safety

- `trust_execution_mode = DISABLED` preserved end to end. A trust allocation cannot open an
  attempt, creates zero commands/outbox rows, and the fake adapter's call counters prove it is
  **never invoked** (FV-A3-053).
- Processor-fee path cannot debit trust principal: a full capture cycle writes zero rows to all
  five trust tables, and the only debited purpose is `PROCESSOR_CLEARING_OPERATING` — never
  OperatingCash, never any trust account (FV-A3-054, §38).
- Fee evidence: DEBIT and CREDIT representable provider-neutrally, magnitudes `>= 0` enforced in
  the constructor, NULL category retained as `unknown`, provider detail opaque
  (FV-A3-050/051/052). No settlement → OperatingCash posting exists anywhere (§38); settlement/fee
  POSTINGS remain deliberately unimplemented — accounts exist, evidence is representable.

## J. Provider-neutrality proof

Structural test (`test_payment_core_has_no_provider_specific_dependency`) scans the whole
`app/Services/Pay` tree, the five Pay models and the Pay enums: no Finix/LawPay import, no
Stripe import (sole allowed exception: the reused, provider-agnostic
`PaymentGatewaySimulationPolicyService`), and no non-comment `finix` token anywhere. FV-A3-001
additionally proves the contract surface itself is clean. Opaque provider references
(`provider_reference`, `provider_metadata`) are the only provider-shaped data core carries.

## K. Database / RLS evidence

**Zero schema changes in Gate A3. Zero migrations. Zero RLS/FORCE RLS changes.** Every new
behavior runs on Gate A2's tables plus the reused integration tables. The RLS coverage registry
is untouched (no new tables). The at-most-once gate's Global/EXEMPT posture and the routing
index's documented no-RLS posture are unchanged, and the sole-writer conventions are respected:
ownership writes go through `ProviderResourceOwnershipService`; gate writes go through
`ProviderOperationAttemptService` (including its public `findByLogicalKey`).

## L. Tests

Filled at certification:

```
Gate A3 targeted:        37 new tests — ProviderPaymentExecutionTest (13),
                         ProviderRefundExecutionTest (7), ProviderEventIngestionTest (7),
                         ProviderFeeTrustSafetyTest (6), ProviderRaceCertificationTest (4)
Pay suite total:         <surface run>
Affected surface:        <surface run>
GitHub certification:    <run id / commit / counts>
```

## M. Files changed

**New (17):** `PaymentProviderAdapter` (contract), `ProviderPaymentRequest`, `ProviderResult`,
`ProviderFeeEvidence`, `FakeProviderEvent` (data), `ProviderOutcome`, `ProviderFeeDirection`
(enums), 3 Pay exceptions (timeout/connection/environment), `FakePaymentProviderAdapter`,
`UnavailablePaymentProviderAdapter`, `ProviderCommandExecutorService`,
`ProviderOutcomeApplierService`, `PaymentOutcomeRecoveryService`, `ProviderEventIngestionService`,
`FirmsVaultPayDispatchHandler`; **5 test classes** + fixture-trait extensions.

**Modified (6, all additive):** `PaymentAttemptService` (optional method-token → canonical
payload), `RefundReservationService` (reason → canonical payload),
`ProviderPaymentJournalRecorderService` (`recordProviderRefund`), `OutboxEventHandlerRegistry`
(one entry), `AppServiceProvider` (one binding), `BuildsPayFixtures` (helpers).

## N. Architecture deviations

1. **OUTCOME_UNKNOWN recovery bypasses `transition()`** — by design, not drift: A2 froze the
   AUTOMATED matrices with no exit from unknown; the applier is the single sanctioned
   authoritative-recovery exit, mirroring the gate's own `resolveReconciliation()` pattern. The
   frozen matrices, and A2's tests asserting them, are untouched.
2. **Recovery settles the gate row to `LocalProcessingComplete` for proven failures too** —
   never `RetryAllowed` (operator-only, per the A2 security review). A fresh charge is a new
   command identity, not a resurrection.
3. **Recovered DECLINED records as FAILED + `failure_reason=declined`** — keeps the unknown-exit
   two-valued (money moved / money did not). Synchronous declines still land as DECLINED.
4. **Deferral and quarantine share one mechanical state** (restricted + retryable), differing
   only in eventual outcome — satisfying §25 and §26 without inventing a tenant-guessing
   distinction the core could not safely make.

## O. Known limitations

1. Settlement/fee **postings** unimplemented (accounts + evidence shape only) — later gate, per §38.
2. Environment context is a POC constant (`sandbox`); real resolution via
   `ProviderEnvironmentResolver` is Gate B wiring.
3. No scheduled recovery/deferred-event sweeper job — services exist; scheduling is later work.
4. Refund journal reverses fee revenue only (POC captures recognize full amount as fee revenue);
   mixed fee/cost refund composition is later-gate work (documented in the recorder).
5. Deferred events are retried by re-ingestion (Gate B webhook redelivery), not by an internal
   scheduler.
6. Fake provider state is per-process memory — sufficient for certification (forked children
   inherit it); Gate B's real provider is stateful by nature.

## P. Final decision

Recorded after GitHub certification — see the conversation report.
