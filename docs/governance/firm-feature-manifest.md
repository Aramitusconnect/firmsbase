# Firm Feature Manifest

**Status:** Living document — Phase 1 deliverable of the Firm Workspace Master Mission.
**Branch:** `fix/real-staging-and-firm-self-registration`
**Scope:** Every firm-facing (or firm-facing-candidate) capability in the repository, discovered via a full repository-wide audit (not just `app/Filament/Firm`) — models, tables, services, policies, RLS status, routes, UI, jobs, import/export, manual-entry readiness, search/filter, and a safety classification for each.

This document is the gate required before any Phase 3+ implementation work begins on the Firm workspace. It supersedes assumptions from earlier, narrower investigations (which looked only at `app/Filament/Firm` and concluded most modules "don't exist" — this audit found the opposite: nearly every domain has a mature, tested, RLS-hardened **backend**, and the real gap is almost entirely **UI**).

**Classification legend**
- **READY** — backend + authorization + RLS solid; safe to build UI on top of today using existing services only.
- **PARTIAL** — backend mostly complete but has a real gap (no service layer, no scheduler, no dispatch wiring, etc.) that must be closed alongside the UI, not just a missing screen.
- **BLOCKED** — structural dependency missing (e.g., no file storage pipeline, no real AI provider) — building UI on top would be misleading or non-functional.
- **PAID ADD-ON** — correctly entitlement-gated today; must stay gated.
- **PLATFORM ONLY** — must never be exposed to firm users.
- **UNSAFE** — real risk if exposed naively (financial ledgers, chargeback/reclass flows, 2FA lockout) — requires action-based (never form-bound) UI and/or a product decision before exposure.

---

## 0. Cross-cutting findings (read first)

1. **The Firm panel currently registers only 4 nav items** (Dashboard, `MatterResource`, `FirmIntegrationResource`, `PlaidItemResource` + 6 Plaid pages). Everything else below is real backend code with **no Firm-facing UI at all**, confirmed by direct source reads, not absence-of-evidence.
2. **No Spatie/RBAC package exists.** Authorization is 100% hand-rolled: a fixed `FirmUserRole` enum (FirmOwner/Attorney/Paralegal/LegalAssistant/Receptionist/BillingStaff) checked via `in_array()` inside one dedicated `*AccessPolicyService` class per domain. Role ceilings are a frozen convention ("may be narrowed, never widened") — there is no way for a Firm Owner to customize permissions, and building that would be an architectural departure, not a missing screen.
3. **Entitlements are separate from roles.** `module_catalog` + `firm_entitlements` + `EntitlementService` gate genuinely-paid modules (`integration`, `plaid`, `ai`, `trust_iolta`, `document_generation`, `forms`, `e_signature`, `client_portal`, `api`, `dedicated_branding`, `practice_area_templates`, etc.). Absence of a row = not entitled (fails closed). Plan-less firms get zero entitlement rows — this is what hides Plaid/Integrations, and is functioning as designed, not a bug.
4. **RLS is essentially fully rolled out.** Every tenant-owned table encountered in this audit is `BelongsToTenant` + FORCE ROW LEVEL SECURITY, wrapped correctly via `TenantContextService::runWithFirmContext()`/`withUserContext()` in every service inspected. A handful of tables deliberately skip `BelongsToTenant` despite carrying `firm_id` (`matter_trust_balances`, `trust_approval_events`, `trust_ledger_entries`, `security_events`'s cross-firm read branch, webhook/Plaid routing tables) — these rely on explicit app-layer `assert*BelongsToFirm()` checks instead, by design, and must keep doing so.
5. **Trust/IOLTA and Plaid are deliberately, structurally firewalled from each other**, enforced by dedicated static-analysis tests (`TrustForbiddenIntegrationsTest`, `FinancialEvidenceTrustLedgerFirewallTest`) that scan source for forbidden references and fail the build if crossed. Do not build a feature that "reconciles trust against the bank feed automatically" without a fresh security review — the team went out of its way to prevent exactly this.
6. **No real file storage or rendering pipeline exists anywhere.** `Document.storage_path` is a bare string column; nothing calls `Storage::disk()->put()`. Document generation output is a `simulated_storage_path` string, never a real PDF. This blocks essentially all of Documents/Files as a genuinely usable feature today.
7. **AI is a complete governance/audit layer with zero real AI.** Every AI service docblock self-discloses "no real provider call, no live UI caller." The only real Filament UI is a platform-admin global-policy page. Do not build firm-facing AI UI until a real `AiProviderAdapterInterface` implementation exists.
8. **The client notification/dispatch pipeline (SMS/WhatsApp/email-to-client) is fully built and fully dormant** — "no real notification system yet" is a stated project rule, and `NotificationDispatchService::dispatch()` has zero production callers. Building a "Send Notification" UI today would create a fully-tracked event trail that ends without ever transmitting anything. Transactional system email (password reset / invitation) is the one real, production-wired exception, backed by a genuine SES send + bounce/complaint consumer daemon.
9. **2FA enforcement exists with no enrollment UI** — a firm set to `Required` today would permanently lock out any non-compliant user, with no self-service recovery path (`ComplianceGapRegistryService` open item, severity High). Do not surface any UI implying 2FA is configurable until an enrollment/recovery flow exists.
10. **Client creation is deliberately gated** through `LeadConversionService::convert()` only — "a lead must not silently become a client" is an explicit project rule. A naive "Create Client" form must not exist.
11. **Manual client payments already work end-to-end** (`ManualPaymentService`), idempotent, routed through `PaymentClassificationService`/`PaymentApplicationService`. This is the safest, highest-value Billing feature to expose first. `TrustIoltaPayment` classification is currently unconditionally blocked pending Trust UI.
12. **Two unrelated "connect a mailbox" systems exist** (`EmailAccount`/`EmailSyncService`, Phase 9, fake provider only vs. the newer `App\Integrations` Microsoft365/GoogleWorkspace providers) — do not conflate them in UI copy; neither has a working real OAuth+sync path today for Email specifically (calendar pull/push is also a no-op for Google/Microsoft despite real API call code existing).

---

## 1. CLIENT / CRM

**BUILT (Tier 1-A).** `App\Filament\Firm\Resources\ClientResource` (read/safe-field-edit only, no Create page — "+ Add Client" is `ClientResource\Actions\AddClientAction`, a header Action that creates a `FirmLead` then immediately calls `LeadConversionService::convert()`, never `Client::create()` directly), `App\Filament\Firm\Resources\ContactResource` (ordinary List/Create/Edit/View), `App\Filament\Firm\Resources\FirmLeadResource` (+ `FirmLeadResource\Actions\ConvertLeadToClientAction`), and a Conflict Check UI wired to `ConflictCheckService` (`MatterResource\RelationManagers\ConflictChecksRelationManager`/`ConflictCheckResultsRelationManager`). Tests: `tests/Feature/ClientCrm/ClientResourceAccessTest.php`, `ContactResourceAccessTest.php`, `FirmLeadResourceAccessTest.php`, `ConflictCheckUiTest.php`, `ClientRelationshipTabsTest.php`.

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Client record (read/manage) | READY | `Client`; no `ClientService::update()`/archive exists | FORCE | None | Creation is **exclusively** via `LeadConversionService::convert()` — never `Client::create()` directly. Update/archive service is a genuine backend gap, not just UI. |
| Contact | READY | `Contact`, direct CRUD safe | FORCE | None | No creation restriction — safe for a normal Filament resource. |
| Firm Lead / Intake | READY (creation) / UNSAFE (status field) | `FirmLead`, `LeadConversionService` | FORCE | None | Lead creation unrestricted; `status = Converted` transition **must** go through `LeadConversionService::convert()`, never a hand-set enum field on a form. |
| Conflict of Interest Check | READY | `ConflictCheckService` | FORCE | None | Sophisticated, safe-to-wire: searches clients/contacts/parties/matter-parties, defaults all matches to `possible_match` (makes no legal judgment), `resolveResult()` blocks self-clearing (only `ConfirmedConflict`/`Dismissed` accepted). |

**What's needed:** A `ClientResource` for read + safe-field edits (never re-triggering conversion logic), a `ContactResource` (straightforward CRUD), a `FirmLeadResource` with a "Convert to Client" action calling `LeadConversionService::convert()` (never an Edit-status-field path), and a Conflict Check UI wired to the existing service. A `ClientService::update()`/archive method needs to be written before a full edit screen can exist.

---

## 2. MATTERS / CASE MANAGEMENT

**BUILT (Tier 3-A).** `MatterResource` (read/view, already registered) carries relation-manager tabs wiring Tasks/Deadlines/TimeEntries/Expenses/Payments/DocumentRequests/Contacts/ConflictChecks onto the Matter view (`MatterResource\RelationManagers\*`), the Conflict Check UI (§1), and now matter **creation** and **opening** as real, safe UI actions. The confirmed backend gap ("no general create-a-matter service; `Matter::create()` only called from `ImportApplyService` and `ProductionPilotWorkflowService`") is closed by a new `MatterCreationService::create()` — additive only, always leaves the new row in `MatterStatus::Draft`, never `Open`. Creation and opening remain two separate concerns per this codebase's own convention: opening a matter is still exclusively `MatterOpeningService::requestConflictCheck()`/`openMatter()`, now reachable via a new "Open Matter" header action (`MatterResource\Actions\OpenMatterAction`) that calls the service directly and surfaces its own `ConflictCheckSummary::isClearToProceed()`-driven result, without duplicating that check in Filament. Role ceiling (`MatterCreationAccessPolicyService`, new dedicated policy service for this domain): FirmOwner/Attorney/Paralegal/LegalAssistant — same set as `ClientCrmAccessPolicyService::CLIENT_MANAGEMENT_ROLES`. Tests: `tests/Feature/Matters/MatterCreationServiceTest.php`, `MatterCreationUiTest.php` (32 tests total, all passing), plus the pre-existing `MatterRelationshipTabsTest.php`.

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Matter (view/list) | READY | `MatterResource` (already registered) | FORCE | **Yes** | `canAccess()` deliberately has no entitlement check ("UX-layer, non-boundary" per its own docblock) — correctly core, not gated. |
| Matter creation | **BUILT (Tier 3-A)** | `MatterCreationService::create()` (new) | FORCE | **Yes** — "+ Add Matter" header action on `ListMatters` | Always creates in `MatterStatus::Draft`; enforces client/firm ownership consistency and matter-type/practice-area consistency; validates assigned attorney/staff are active `FirmUser`s of the firm. Exposes only real `matters` columns — no invented title/matter-number/description/court/billing-arrangement/related-contacts fields (none exist on the schema). |
| Matter opening (conflict-gated) | **BUILT (Tier 3-A)** | `MatterOpeningService` (unchanged) | FORCE | **Yes** — "Open Matter" header action on `ViewMatter` | "The ONLY place a matter may transition to open" — gated on `ConflictCheckSummary::isClearToProceed()`. The new Action calls this directly and never sets `status` itself; the Action stays visible once `conflict_review` is reached and surfaces the service's own accept/reject result rather than re-implementing the clearance check. |
| Practice Areas | READY | (backend, not re-audited in depth beyond confirming existence) | FORCE | None | |

**What's needed:** Nothing further for Tier 3-A — creation and opening are both real, service-mediated, and tested.

---

## 3. TASKS / WORKFLOW &amp; DEADLINES

**BUILT (Tier 1-B).** `App\Filament\Firm\Resources\TaskResource` (Create/Edit route through `TaskService`; Start/Complete/Cancel/AddDependency row Actions call `TaskService`/`TaskDependencyService` directly) and `App\Filament\Firm\Resources\DeadlineResource` (Create routes through `DeadlineService::create()`; Complete/Cancel row Actions). The scheduler gap noted below (overdue/missed-status refresh, reminder dispatch) is unchanged and remains a known follow-up — both resources add an honest, purely-computed "Due" column rather than trusting a possibly-stale stored status. Tests: `tests/Feature/TasksDeadlines/TaskResourceAccessTest.php`, `DeadlineResourceAccessTest.php`.

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Tasks | READY | `TaskService`, `TaskDependencyService` | FORCE | None | Simple, manual-entry-friendly. `TaskDependencyService::addDependency()` rejects cycles via BFS — always use it, never direct `TaskDependency::create()`. |
| Task/Deadline overdue-status refresh | PARTIAL | `TaskService::refreshOverdueStatus()`, `DeadlineService::refreshMissedStatus()` | FORCE | None | Never called by any scheduled command — Overdue/Missed states won't reflect reality without new scheduler wiring alongside the UI. |
| Deadlines | PARTIAL | `DeadlineService::create()` (auto-creates linked `CalendarEvent`), `reminderDates()` | FORCE | None | `reminderDates()` is a pure calculation only — nothing dispatches a reminder. Building a "Deadlines" UI without wiring a scheduler would visually promise reminders that never fire. |

**What's needed:** A `TaskResource` + `DeadlineResource` (straightforward, service-mediated). A new scheduled command for both overdue/missed-status refresh and deadline-reminder dispatch (the latter depends on the dormant notification pipeline, §8 — see cross-cutting #8).

---

## 4. CALENDAR / SCHEDULING

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Internal Calendar | PARTIAL | `CalendarEventService::createStandalone()`/`createFor()` | FORCE | None | Write path solid; zero read/search infra, no recurrence, no explicit timezone handling. |
| External Calendar sync (Google/Microsoft) | BLOCKED (misleading if surfaced as working) | `GoogleWorkspaceProvider`, `Microsoft365Provider` | (framework-level) | Connect toggle only, via `FirmIntegrationResource` | Real HTTP calendar API code exists for both pull and push, but the framework's materialization hook is deliberately unbuilt for calendar data — pull never writes to `calendar_events`, and nothing enqueues a push. Do not present this as "syncs with Google/Outlook" — it doesn't, yet. |

**What's needed:** A `CalendarEventPolicy` + a List/Calendar Filament page (needs a FullCalendar-style Livewire package — Filament has no native calendar UI) + a Create form calling `createStandalone()`. External sync is a separate, larger initiative (observer + calendar-specific materializer) — do not bundle into the internal-calendar UI ticket.

---

## 5. DOCUMENTS / FILES / FORMS / E-SIGNATURE

**Document Requests + Document Chase BUILT (Tier 1-F).** `App\Filament\Firm\Resources\DocumentRequestResource` (Create routes through `DocumentRequestService::create()`; per-item status Actions on `DocumentRequestResource\RelationManagers\ItemsRelationManager`, never a raw model write) and `App\Filament\Firm\Resources\DocumentChaseRuleResource` (plain CRUD — no dedicated write service exists for this model, confirmed by source read — every page/copy is explicit that rule configuration never implies a reminder is actually sent). Storage/generation/e-signature below remain BLOCKED/PARTIAL, unchanged. Tests: `tests/Feature/Documents/DocumentRequestAccessTest.php`, `DocumentChaseAccessTest.php`.

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Document storage/upload | **BLOCKED (structural)** | `Document` | FORCE | None | No real storage backend wired anywhere — `storage_path` is just a string column. The provisioned S3 bucket (`infrastructure/ecs/modules/s3_documents`) is explicitly undocumented as unused by app code. **This blocks the whole category from being a genuinely usable feature.** |
| Document generation | BLOCKED | `DocumentGenerationService` | FORCE | None | Fully simulated output (`simulated_storage_path` is descriptive text only, never a real PDF/DOCX). |
| E-Signature | PARTIAL (backend complete, no delivery UI) | `SignatureRequest`→`SignatureRequestRecipient`→`SignatureEvent`→`SignatureCertificate` | FORCE, append-only enforced at RLS + model level | None | Complete, heavily-tested, evidentiary-grade state machine (first-party only, no DocuSign/HelloSign). No signer-facing delivery route exists; certificate is JSON-only, no rendered PDF. |
| Document Requests (client document-collection) | READY | `DocumentRequest`/`DocumentRequestItem` | FORCE | None | No storage dependency — safest win in this whole category. |
| Document Chase (automated reminders) | PARTIAL | `DocumentChaseRule`/`DocumentChaseEvent` | FORCE | None | Computes eligibility/logs only; no scheduler ever dispatches a reminder. |

**Entitlements:** `document_generation`, `forms`, `e_signature` are separate `module_catalog` codes — likely paid add-ons; keep gated.

**What's needed:** A real storage decision (wire the existing S3 module + a Laravel filesystem disk) is the actual blocker for Documents proper — do this before any upload UI. Document Requests + Document Chase can ship UI today with zero new backend work (Chase still needs a scheduler for the reminder half). E-Signature needs a real signer-facing delivery route before it's a usable feature end-to-end, even though its core state machine is production-ready.

---

## 6. TIME TRACKING / EXPENSES / BILLING

**Time Entries, Expenses, Expense Reporting, Manual Client Payments, Invoices, and Payment Plans all BUILT (Tier 1-C/1-D, Tier 2-A).** `App\Filament\Firm\Resources\TimeEntryResource` (Create routes through `TimeEntryApprovalService::createManualEntry()`; Start/Stop Timer header actions wired to the real `TimeTrackingService`; Submit/Approve/Reject row Actions), `App\Filament\Firm\Resources\ExpenseResource` (Create routes through `ExpenseService::create()`; Submit/Approve/Reject/Void row Actions; entitlement-gated on the `expenses` module_catalog code — confirmed hidden entirely for a disentitled firm, not merely greyed out), `App\Filament\Firm\Pages\ExpenseReportPage` (read-only, wired to `ExpenseReportingService`), and `App\Filament\Firm\Resources\PaymentResource` (List/View only — "Record Payment" is exclusively `PaymentResource\Actions\RecordPaymentAction`/`RecordClientPaymentAction`, both routed through `ManualPaymentService::submit()`, never a `Payment::create()`). **Tier 2-A added `App\Filament\Firm\Resources\InvoiceResource`** (List/View only; Draft-from-Time-Entries/Create-Flat-Fee/Add-Manual-Charge/Submit/Approve/Send/Void Actions, each calling the exact matching `InvoiceDraftingService` method — no `status`/total field is ever form-editable, proven by a dedicated financial-invariant source-scan test) **and `App\Filament\Firm\Resources\PaymentPlanResource`** (List/View only; Create/Activate/Renegotiate/Cancel/Mark-Defaulted Actions calling `PaymentPlanService`/`PaymentPlanInstallmentService` — Renegotiate provably creates a new superseding plan row rather than mutating the original, redirecting the user to the new record). Tests: `tests/Feature/TimeExpenses/TimeEntryResourceAccessTest.php`, `ExpenseResourceAccessTest.php`, `ExpenseReportPageTest.php`, `tests/Feature/Payments/PaymentResourceAccessTest.php`, `tests/Feature/Billing/{InvoiceResourceAccessTest,PaymentPlanResourceAccessTest,BillingFinancialInvariantTest}.php`.

| Feature | Status | Models / Services | RLS | Firm UI today | Notes |
|---|---|---|---|---|---|
| Time Entries | READY | (service layer, manual-entry-friendly) | FORCE | None | |
| Expenses | READY | `Expense`, `ExpenseReportingService` (read aggregation) | FORCE | None | Reporting service is real and tested but has zero UI consumer. |
| Invoices | UNSAFE if exposed as raw CRUD | `InvoiceDraftingService` (draftFromTimeEntries/createFlatFee/addManualCharge/submitForReview/approve/send/void) | FORCE | None | Totals are always derived/cached — never expose `status`/totals as editable form fields; every mutation must be a named Action calling the service. |
| Payment Plans | UNSAFE if exposed as raw CRUD | `PaymentPlanService`/`PaymentPlanInstallmentService` | FORCE | None | `renegotiate()` creates a new plan row, never mutates in place; `markDefaulted()` is explicit human-only, never automatic. |
| Manual Client Payments | **READY — highest-value near-term win** | `ManualPaymentService::submit()` | FORCE | None | Idempotent (DB partial unique index), routes through `PaymentClassificationService`→`PaymentApplicationService`. `ManualPaymentMethod`: Cash/Check/BankTransfer/CardManualEntry/Other. `TrustIoltaPayment` classification currently unconditionally blocked (Trust UI doesn't exist yet). No real Stripe/LawPay client-payment processor exists at all — only FirmsBase's own SaaS billing of firms uses (simulated) Stripe. |

**What's needed:** `TimeEntryResource` + `ExpenseResource` (straightforward). A read-only `ExpenseReportPage` wired to `ExpenseReportingService`. Invoice/PaymentPlan resources must be Action-based only (Draft/Submit/Approve/Send/Void/Renegotiate/Cancel/MarkDefaulted actions calling the named service methods), never generic Filament Edit forms. `ManualPaymentService::submit()` is ready to wire behind a "Record Payment" action today.

---

## 7. TRUST ACCOUNTING / IOLTA

**BUILT (Tier 2-B).** Read-only `App\Filament\Firm\Resources\{TrustAccount,TrustLedger,TrustLedgerEntry}Resource` (the latter `ViewRecord`/`ListRecords` only — no Create/Edit page exists anywhere for it) plus action-based mutation UI for every workflow below, each Action calling exactly one `Trust*Service` method — **zero existing Trust service/model file was modified**. The entire "Trust Accounting" nav group is hidden completely (not merely disabled) unless `TrustEligibilityService::isEligible($firm)` is true. A new static-scan firewall test (`tests/Feature/Trust/Firewall/TrustFilamentForbiddenMutationsTest.php`, mirroring `TrustForbiddenIntegrationsTest`'s technique) asserts no Filament file in this module ever calls `create()`/`update()`/`save()`/`firstOrCreate()`/`updateOrCreate()` on any of the 10 Trust models, and a dedicated test proves no form field anywhere binds to `balance_cents`. High-risk adjustment's second-approve Action excludes the first approver from its own approver picker as a UI-level belt-and-suspenders on top of the service's own `assertDistinctApprovers()`. Reconciliation exposes no auto-correct action on a `Discrepancy` result. Tests: `tests/Feature/Trust/Filament/*` + the firewall test (122 tests total, all passing).

| Sub-feature | Safe entry point (service method) |
|---|---|
| Open/suspend/close trust account | `TrustAccountService::open()/suspend()/close()` |
| Open/freeze/close client ledger | `TrustLedgerService::open()/freeze()/close()` |
| Deposit | `TrustDepositService::requestDeposit()` → `approveDeposit()` → `post()` |
| Transfer to invoice | `TrustTransferRequestService::requestTransfer()` → `approveTransfer()/denyTransfer()` → `apply()` |
| Refund to client | `TrustRefundRequestService::requestRefund()` → `approveRefund()/denyRefund()` → `complete()` |
| High-risk adjustment (two distinct approvers required) | `TrustHighRiskAdjustmentService::requestAdjustment()` → `firstApprove()` → `secondApprove()` |
| Chargeback | `TrustChargebackService::report()` → `reverse()` → `resolve()` |
| Bank reconciliation | `TrustReconciliationService::run()` — never auto-corrects a discrepancy |

**Hard rules (must be enforced by any future UI):**
- **Never** implement direct raw CRUD on any `Trust*` model — `TrustLedgerEntry`/`TrustApprovalEvent`'s `booted()` guards block *update*, but not an unguarded, unvalidated `create()`.
- **Never** bind a form field directly to `balance_cents` on `trust_balances`/`matter_trust_balances` — only `TrustBalanceService` may write it, purely by convention (no DB constraint).
- Every mutation must be a Filament `Action` calling exactly one `Trust*Service` method — never a `CreateRecord`/`EditRecord` page bound to model fields.
- Trust mode itself requires a real, two-person, platform-admin-approved activation per firm (`TrustEligibilityService`) before any trust action is possible — use `TrustEligibilityService::isEligible($firm)` to gate nav/resource visibility, not just runtime exceptions.
- Jurisdiction-specific IOLTA compliance (three-way reconciliation cadence, permitted-bank lists, signature-card requirements) is **explicitly unimplemented** — a UI here still needs external human compliance review per state bar; this backend provides ledger mechanics only.

**What's needed:** Read-only Resources first (`TrustAccountResource`, `TrustLedgerResource`, a `ViewRecord`-only `TrustLedgerEntryResource`). Then Action-based mutation UI. A Filament-layer Policy class mirroring `TrustAccessPolicyService`/`TenantSafeTrustPolicyService`'s role ceilings (Request: FirmOwner/Attorney/BillingStaff; Approve: FirmOwner/Attorney only, distinct approvers for adjustments). A new static-scan test (mirroring `TrustForbiddenIntegrationsTest`'s discipline) asserting no Trust Filament Resource ever calls a `Trust*` model's `create/update/save` directly.

---

## 8. BANKING / PLAID (paid add-on)

**Status: PAID ADD-ON, functionally deep, correctly gated.** Framed as a "Financial Evidence" litigation-discovery add-on, not bookkeeping reconciliation. Gated on a separate `plaid` entitlement (distinct from base `integration`). Already has real Firm-facing UI (`PlaidItemResource` + 6 pages) — not re-audited in UI depth per prior work, but backend confirmed complete: 21 tables (FORCE RLS except two deliberate bootstrap exceptions), 7 immutable evidence-materializer tables (Auth/Identity/Income/Liabilities/Investments/Statements/Transactions), a 17-step cost-control billable-call pipeline, heuristic large-deposit/duplicate-transfer/recurring-obligation detection.

**Confirmed firewalled from Trust (§7)** — enforced by `FinancialEvidenceTrustLedgerFirewallTest`, a static scanner covering 30+ files with negative-proof tests. The one legitimate touchpoint (`FinancialEvidenceReconciliationCandidateDetectionService`) is read-only and never posts to any `Trust*` table. **Do not build "auto-reconcile trust against bank feed" without a fresh, deliberate security review** — the team built a test specifically to catch this.

**Keep gated. No action needed** beyond what already exists, unless the user wants the Financial Evidence workflow deepened.

---

## 9. REPORTING / ANALYTICS

| Feature | Status | Notes |
|---|---|---|
| Expense reporting | **BUILT (Tier 1-C)** | `App\Filament\Firm\Pages\ExpenseReportPage`, read-only, wired to `ExpenseReportingService`. |
| Financial Evidence report export (Plaid, matter-scoped) | READY (already shipped) | PDF/CSV export of an immutable snapshot — narrow scope, not general reporting; do not conflate with business reporting. |
| Attorney productivity / billing-collections / trust-reconciliation reports | **Not built at all** | No models, services, or UI found for any of these — genuinely new work, not a hidden feature. |

**What's needed:** An `ExpenseReportPage` (see §6). Productivity/billing/collections/trust-reconciliation reports are new backend + UI work — scope separately, likely lower priority than the READY items elsewhere.

---

## 10. AUDIT LOGS

Two distinct systems — do not conflate:

| System | Scope | Firm-facing today? | Status |
|---|---|---|---|
| `SecurityEvent` (`security_events`) | Security/privileged-action trail (logins, support access, high-risk platform changes, webhook replay) | No — platform-admin dashboard only | PARTIAL — RLS already permits a firm to read its own rows; no firm code path exists |
| `TimelineEvent` (`timeline_events`) | General business-activity trail (payments, document chases, conflict checks, etc.) | Partial — one matter "Activity" tab exists, **but is structurally empty** (no `matter.*`-prefixed events are emitted anywhere yet) | PARTIAL |
| Generic activity-log package | — | — | Not present (no `spatie/laravel-activitylog`); both systems above are hand-rolled |
| `ImportAuditEvent` | Platform-admin data-migration audit | No | **PLATFORM ONLY — do not expose** |

**What's needed:** For `SecurityEvent`: a `FirmSecurityActivityPage` (FirmOwner-only) reading `SecurityEvent::query()` inside `runWithFirmContext()` — RLS already scopes correctly. Redact `metadata`; likely exclude/summarize `support_access` and `high_risk_change` categories (product decision needed) — `login_succeeded`/`login_failed` is the safest subset to ship first. For `TimelineEvent`: the real blocker is that almost no `matter.*` event types are emitted yet — wiring `TimelineEventRecorder::record()` into real mutation call sites is prerequisite work before a "firm audit log" UI is meaningful, not a UI-only task.

---

## 11. SECURITY (firm-facing)

**2FA self-service enrollment + Firm Security Activity page BUILT (Tier 3-D).** `FirmPanelProvider` now registers `->profile()` + `->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: false)` — deliberately `false`, not a dynamic closure: verified directly in Filament's own source (`HasRoutes::getRouteMiddleware()`) that `isRequired` is evaluated at panel **route-registration time**, during app boot, before any request/session/tenant context exists — a closure could never genuinely reflect a specific user's firm's `firm_user_2fa_mode` at that point, so `false` (self-service opt-in only) is the only safe choice, not a workaround. `User` now implements `HasAppAuthentication`/`HasAppAuthenticationRecovery` (mirroring `PlatformAdmin`, reusing the same `two_factor_*` columns) — stock Filament TOTP is used, NOT `AuditedAppAuthentication` (that class's audit hooks are hard-gated to `PlatformAdmin` and would falsely imply an audit trail that doesn't exist for firm users). Recovery reuses Filament's own `->recoverable()` one-time-code mechanism, the same one already proven safe for the admin panel — no custom recovery system was built. **The critical regression test — an existing, non-2FA-enrolled firm user in a non-Required firm still logs in exactly as before — was run first, before any other work, and passes.** `User::canAccessPanel()`/`FirmUser2faPolicyService` were not modified. `firm_user_2fa_mode`/`client_2fa_mode` remain entirely un-toggleable (no UI anywhere sets them to `Required`) — this task deliberately did not touch `FirmSettingsPage`.

`App\Filament\Firm\Pages\FirmSecurityActivityPage` (FirmOwner-only) reads the firm's own `SecurityEvent` rows (RLS already permits this). `login_succeeded`/`login_failed` shown plainly; `support_access`/`high_risk_change` categories are heavily summarized (fixed sentence + timestamp, no actor identity, no metadata); any other category is excluded entirely (conservative default); raw `metadata` is never selected from the DB. Tests: `tests/Feature/FirmSecurity/*` (21 tests, all passing, including the critical login-regression test run first).

| Feature | Status | Notes |
|---|---|---|
| 2FA self-service enrollment | **BUILT (Tier 3-D)** | Opt-in only (`isRequired: false`, by design — see above). Enforcement itself (`FirmUser2faPolicyService`) is unchanged and still has no way to be set to `Required` via any UI. |
| Login policy (password rules, lockout, session timeout) | PARTIAL / mostly decorative | Unchanged this mission — only the membership-check half (`canAttemptFirmLogin()`) is actually wired. |
| Firm-facing login history / security dashboard | **BUILT (Tier 3-D)** | `FirmSecurityActivityPage`, see above. |
| Session management (view/revoke active sessions) | Not built | No code anywhere implements this. |
| Support-access transparency (platform admin touching firm data) | **Partially addressed** | Now summarized (non-identifying) on `FirmSecurityActivityPage`; still no dedicated deep-dive view. |

**Still needed:** Real password-policy validation wired into the invitation/reset flow; session management; a UI path to ever safely set `firm_user_2fa_mode = Required` (would need its own dedicated safety review beyond this task's opt-in-only scope, plus likely a firm-initiated grace period / admin-assisted recovery path for a user who loses both their authenticator and their recovery codes).

---

## 12. FIRM TEAM / ACCESS

**BUILT (Tier 3-B).** A Firm Owner can now invite, view, suspend, reactivate, and remove team members entirely from the firm panel — closing the "zero firm-facing mutation" gap this section previously described.

`App\Services\FirmUserInvitationService::invite(Firm, string $email, string $name, FirmUserRole $role, User $invitedBy): FirmUser` is the sole firm-facing invite path — it never calls `FirmUser::create()` directly from UI code. It reuses `FirmProvisioningService`'s exact owner-creation shape (an unusable-password `User` + an `Invited`-status `FirmUser`), reuses `FirmOwnerInvitationNotification` AS-IS (confirmed role-agnostic by direct source read — nothing in the class references "owner"), and reuses `CorrelatedPasswordResetSenderService::sendForFirm()` for the actual send. `App\Filament\Firm\Pages\Auth\ResetPassword::hasPendingFirmOwnerInvitation()` already accepted any role's `Invited` status (no role filter in its query), so the invitation-acceptance flow needed no changes to serve non-owner invitees. The same service's `suspend()`/`reactivate()`/`remove()` cover the lifecycle, each wrapped in `TenantContextService::runWithFirmContext()` with TOCTOU re-fetch discipline.

**Seat model (updated — flat per-firm licensing, closing the previously-documented "zero seats" gap):** each firm purchases ONE flat number of seats — `firm_licenses.purchased_seats` (migration `2026_08_08_100010_add_purchased_seats_to_firm_licenses_table`, nullable integer) — not fixed per-`SeatClass` (Attorney/Staff/ReadOnly) quotas. `Plan` controls FEATURES (module entitlements); `FirmLicense.purchased_seats` controls the purchased SEAT QUANTITY. Every `FirmUser` row — any of the 6 roles, including `FirmOwner` — consumes exactly one seat; a `Suspended` row still consumes a seat (administrative/temporary, matches typical SaaS semantics — documented as a judgment call in `FirmSeatCapacityService`'s own docblock); only `Removed` frees it, which falls out naturally from the count-based check (Active+Invited+Suspended) with no separate "release" action and no deletion of any historical row.

`App\Services\FirmSeatCapacityService` (new — `purchasedSeats()`/`usedSeats()`/`remainingSeats()`/`canInvite()`) is the sole authoritative source for this check, reading `FirmLicense.purchased_seats` directly and counting `firm_users` rows under tenant context. `FirmUserInvitationService::invite()` now calls `FirmSeatCapacityService::canInvite()` instead of the original per-class `SeatEnforcementService::canInvite()`, and `FirmSeatLimitExceededException`'s message is now flat and end-user-appropriate ("Your firm has used all N licensed user seats..."), no per-class language.

**`SeatPool`/`SeatAllocation`/`SeatAllocationService`/`SeatClass`/`SeatEnforcementService` are deliberately left completely untouched and dormant** — re-confirmed by a fresh source-wide re-scan that `SeatEnforcementService::usageFor()`/`canInvite()` is still the only production consumer of `seat_allocations` data, and that consumer is itself now only reachable from `DowngradeEvaluationService::evaluate()` (a read-only computation with no real production caller of its own — confirmed no route/action/job invokes it). Preserved for possible future per-class authorization/accounting use, per explicit product direction, rather than forced into representing one flat number across 3 mandatory `SeatClass` rows (which would either triple-count capacity or arbitrarily favor one class).

`FirmProvisioningService::provision()`/`FirmProvisioningInput` now accept a `purchasedSeats` field, required and validated (`InvalidPurchasedSeatsException`) whenever `planId` is also supplied — a plan-less firm still gets no `FirmLicense` at all (unchanged prior behavior). `ProvisionFirmAction`'s wizard and the `firms:provision` console command both collect this field. Pre-existing commercial firms (provisioned before this column existed) are backfilled via `php artisan firms:report-missing-purchased-seats` (dry-run report by default) / `--apply --firm=<id> --seats=<n>` (explicit, operator-supplied, one firm at a time, idempotent, `--force` required to overwrite a differing existing value) — this command never invents a seat quantity. `ListFirmUsers` now shows a `TeamSeatUsageWidget` header widget ("Team Members — X of Y seats used" or a clear "no licensed seats configured" message); "+ Invite Team Member" stays clickable at capacity and fails cleanly via the service-level exception (informational widget, not a hidden-action guard — see that widget's own docblock for why).

`App\Services\FirmMembershipAccessPolicyService` (new — deliberately NOT a Laravel Policy registered against `App\Models\FirmUser`, since that model class already has a global `Gate::policy()` registration to the platform-admin-only `FirmUserPolicy`, whose methods are strictly typed to `PlatformAdmin` and would fatal with a `TypeError` if Gate ever resolved them for a `web`-guard `User` actor — exactly the "future firm-panel use case" `FirmPolicy`'s/`FirmUserPolicy`'s own docblocks had already flagged as an open hazard) gates: VIEW the team roster — every active `FirmUserRole`; MANAGE (invite/suspend/reactivate/remove) — **FirmOwner only**, the narrowest ceiling in this mission, since granting/revoking another person's access to the whole practice is more consequential than any Trust/Billing action already gated this tightly.

`App\Filament\Firm\Resources\FirmUserResource` (new, "Firm Management" nav group, first resource in it) — List/View only, no generic Create/Edit page; "+ Invite Team Member" (`InviteFirmUserAction`, role Select built from `FirmUserRole::cases()` — exactly 6 real roles, no platform-admin concept can leak in) and Suspend/Reactivate/Remove (row/header Actions, all requiring confirmation) are the only mutation surfaces. Runs inside the already-active tenant context (a normal Eloquent query, unlike the platform-admin `FirmUserResource`'s cross-firm directory-service read). The **last-active-owner guard** (`LastFirmOwnerRemovalException`) is enforced in the service itself, not just the UI — proven directly against the service (blocked/allowed cases) and through the UI (action stays visible but the call fails cleanly).

Tests: `tests/Feature/FirmTeam/FirmUserInvitationServiceTest.php` (12, updated for the flat seat model), `tests/Feature/FirmTeam/FirmUserResourceAccessTest.php` (15, includes the RLS regression checklist — own-firm-only list, no cross-firm leak, direct-URL-to-foreign-record blocked), plus `FirmUserResource::class` added to `FirmWorkspaceCompletenessGuardTest`'s roster. No regression in `ResetPasswordInvitationAcceptanceTest`/`ClientCrm`.

**Flat seat model test coverage, this pass:** `tests/Feature/Foundation/PurchasedSeatsMigrationTest.php` (3), `tests/Feature/FirmTeam/FirmSeatCapacityServiceTest.php` (12, including the cross-firm RLS regression checklist for `firm_licenses.purchased_seats`), `tests/Feature/Services/FirmProvisioningSeatsTest.php` (6), `tests/Feature/Filament/Platform/ProvisionFirmActionTest.php` (+2), `tests/Feature/Console/ProvisionFirmCommandSeatsTest.php` (2), `tests/Feature/FirmTeam/TeamSeatUsageWidgetTest.php` (6), `tests/Feature/FirmTeam/SeatArchitectureUntouchedTest.php` (4, proves `SeatAllocation`/`SeatPool`/`SeatAllocationService`/`SeatClass`/`SeatEnforcementService` were not modified and are not referenced in real code by the new service), `tests/Feature/Console/ReportMissingPurchasedSeatsCommandTest.php` (11). All new and updated tests pass; full repository suite re-run with 0 failures.

Files: `app/Services/FirmUserInvitationService.php`, `app/Services/FirmMembershipAccessPolicyService.php`, `app/Exceptions/FirmSeatLimitExceededException.php`, `app/Exceptions/LastFirmOwnerRemovalException.php`, `app/Filament/Firm/Resources/FirmUserResource.php` + `FirmUserResource/{Pages,Actions,Widgets}/*`. The platform-admin `App\Filament\Resources\FirmUserResource` and `App\Policies\FirmUserPolicy` were **not** modified.

**Flat seat model addition, this pass:** `database/migrations/2026_08_08_100010_add_purchased_seats_to_firm_licenses_table.php`, `app/Models/FirmLicense.php` (+`purchased_seats`), `app/Models/Firm.php` (+`license()` accessor), `app/Services/FirmSeatCapacityService.php` (new), `app/Exceptions/InvalidPurchasedSeatsException.php` (new), `app/ValueObjects/FirmProvisioningInput.php` (+`purchasedSeats`), `app/Services/FirmProvisioningService.php` (+validation and `FirmLicense::create()` wiring), `app/Filament/Actions/Platform/ProvisionFirmAction.php` (+form field), `app/Console/Commands/ProvisionFirmCommand.php` (+option), `app/Filament/Firm/Resources/FirmUserResource/Widgets/TeamSeatUsageWidget.php` (new), `app/Filament/Firm/Resources/FirmUserResource/Pages/ListFirmUsers.php` (+header widget), `app/Console/Commands/ReportMissingPurchasedSeatsCommand.php` (new backfill command). `SeatPool.php`/`SeatAllocation.php`/`SeatAllocationService.php`/`SeatClass.php`/`SeatEnforcementService.php` were **not** modified.

---

## 13. FIRM SETTINGS

**BUILT (Tier 3-C).** `App\Filament\Firm\Pages\FirmSettingsPage` (singleton, FirmOwner-manage / all-roles-view). A small, additive migration (`2026_08_07_100001_add_address_and_phone_to_firms_table.php`) added nullable `address_line1`/`address_line2`/`city`/`postal_code`/`phone_number` to `firms` — the previously-missing profile fields the mission explicitly asked for. **Editable**: `Firm.legal_name/primary_country/primary_state/default_timezone/default_currency` + the 5 new address/phone columns, `FirmSettings.default_language/state_jurisdiction`, and a narrowly-scoped branding pair (`branding_settings_json.display_name_override`/`.primary_color`, text-only, no logo/file upload since no storage pipeline exists). **Read-only display, not editable**: `payment_mode`/`trust_iolta_protection`/`ai_mode` (these gate other safety-critical systems — "contact platform support to change this"). **Excluded totally, by design**: `firm_user_2fa_mode`/`client_2fa_mode` — zero form fields, verified by a source-scan test; these remain deferred to §11's 2FA work. `security_settings_json` untouched. Tests: `tests/Feature/FirmSettings/*` (53 tests) + `tests/Feature/Foundation/FirmAddressPhoneMigrationTest.php` (3 tests) — all passing, including forged-submission tests proving the read-only/excluded fields have zero write effect even if an attacker crafts a raw payload.

---

## 14. AI

**Status: BLOCKED — governance-before-feature, explicitly and by design.** A complete, well-tested policy/audit layer (8 models, all FORCE-RLS where firm-scoped, encrypted approval snapshots, append-only usage/tool-action logs, 6 mandatory-approval high-risk categories) sits on top of **zero real AI capability** — the only registered provider adapter is `FakeAiProviderAdapter` (deterministic echo, explicitly forbidden from any network call by its own interface docblock). Every service's docblock self-discloses "no route/controller/UI calls this." The only real Filament UI anywhere is `AiPolicySettingResource` (platform-admin, global policy only).

**Do not build firm-facing AI UI yet** — there is no real output to show. The one safe, low-value thing to expose today (a read-only `AiUsageEvent`/`AiApprovalRequest` view) would currently render empty, since nothing populates it in production.

**What's needed (real, sequenced, non-UI-first) prerequisite work:** (1) Implement at least one real `AiProviderAdapterInterface` (OpenAI/Anthropic via Guzzle) — the actual blocker. (2) Wire a genuine entry point (e.g., "Summarize this document" on `MatterResource`) calling `AiUsageRecorderService::record()`. (3) Then, and only then, build firm-facing `FirmAiSettings` UI (FirmOwner-only), `FirmAiProviderKey` management (key material shown once only), and an approval queue for the 6 high-risk categories (FirmOwner/Attorney).

---

## 15. INTEGRATIONS (beyond Plaid)

| Integration | Real adapter code? | Wired end-to-end? | Firm UI |
|---|---|---|---|
| Microsoft 365 | Yes (OAuth, pull/push, webhooks) | **No** — disabled by default, `PullSyncJob`/`PushSyncJob` never thread the connection context in; Gmail-equivalent webhook routing confirmed non-functional by the class's own docblock | Only via generic `FirmIntegrationResource` |
| Google Workspace | Yes | **No** — same gaps as above; calendar pull/push is a confirmed no-op for calendar data specifically (§4) | Only via generic `FirmIntegrationResource` |
| QuickBooks | Label enum only | **No** — fully simulated one-way fake CSV/JSON export, no real API | None dedicated |
| Clio / LawPay / firm-facing Stripe | **Do not exist** | — | — |

**Do not build UI implying these integrations sync data today.** Real wiring (threading `FirmIntegration` context into `pull()`/`push()`, fixing Gmail webhook routing) is prerequisite backend work, separate from any UI ticket.

---

## 16. COMMUNICATIONS / NOTIFICATIONS

**Communication Consent BUILT (Tier 1-E).** `App\Filament\Firm\Resources\CommunicationConsentResource` (firm-wide List/View + `CaptureConsentAction`/`RevokeConsentAction`, both routed through `ConsentService::capture()`/`revoke()`) plus a client-scoped counterpart on `ClientResource\RelationManagers\CommunicationConsentsRelationManager`. Everything else in this section is unchanged. Test: `tests/Feature/Communications/CommunicationConsentAccessTest.php`.

| Sub-feature | Status | Notes |
|---|---|---|
| System auth email (reset/invite) | **READY — real, production-wired** | Genuine AWS SES send with a configuration set; the one fully-live path in this whole category. |
| SES bounce/complaint consumer | **READY — real ECS daemon** | `ConsumeSesEventsCommand`, long-polls SQS, feeds firm-scoped suppression state. |
| Client notification dispatch (email/SMS/WhatsApp/portal to clients) | **BLOCKED — explicitly dormant** | Fully built (consent, eligibility, suppression, templates, domain verification, event log), zero production callers, project rule "no real notification system yet." Building a "Send" UI today would fake-send. |
| Communication consent | **BUILT (Tier 1-E)** | `ConsentService` — solid, append-only audit trail; UI now shipped, see above. |
| Client communication preferences | PARTIAL | Model + RLS exist, **no service layer** (unusual — every other domain has one) — write a thin service for consistency before building a form. |
| Email/mailbox sync &amp; client correspondence | **BLOCKED — fake provider only** | The single biggest "backend built, zero UI" gap in the repo: full encryption, visibility-rule, attachment-promotion, metadata-only-search architecture — but only a fake email provider client exists, no real Gmail/Microsoft OAuth. Read-only inbox UI could be built against fixture data today; "Connect Mailbox" must not be exposed until real OAuth exists (or must be clearly labeled preview-only). |
| SMS / WhatsApp | Not built | Enum placeholders only, no provider config, no service. |

**What's needed:** Communication Consent UI is a safe, real near-term win. Client-facing dispatch and mailbox sync both need explicit product decisions ("wire a real transport" / "build real OAuth") before UI — do not build screens that imply either already works.

---

## 17. OTHER FEATURES / MODULE CATALOG

`module_catalog` seeds 17 module codes total, all referenced above except: `client_portal` (separate portal panel, out of this audit's scope), `api` (no firm-facing API-key management UI found), `dedicated_branding` (ties to the unused `branding_settings_json`, §13), `practice_area_templates` (not independently audited — appears tied to Matters/Practice Areas, §2).

---

## 18. FIRM WORKSPACE UI COMPLETENESS (Quick Add + regression guard)

**BUILT (Tier 1-H) — the final item of the Tier 1 build-out.**

**Global "Quick Add" header menu.** `App\Filament\Firm\Livewire\FirmTopbar` (a subclass of Filament's own `Topbar` Livewire component, wired in via the Filament v4 extension point `Panel::topbarLivewireComponent()`) hosts the two modal-backed Quick Add actions so they are mountable from any page in the panel; `App\Providers\Filament\FirmPanelProvider` registers a `Panel::renderHook(PanelsRenderHook::TOPBAR_END, ...)` that injects the dropdown UI (`resources/views/filament/firm/quick-add-menu.blade.php`) into that same component's DOM. The menu contains exactly nine items — `+ Client`, `+ Contact`, `+ Lead`, `+ Task`, `+ Deadline`, `+ Time Entry`, `+ Expense`, `+ Payment`, `+ Document Request` — deliberately excluding `+ Matter` (creation service doesn't exist yet, §2) and anything for Trust/Invoices/AI/Documents-file-upload (none exist yet either, §§5/7/14). Each item reuses the exact same Action class or `CreateRecord` page route each resource's own creation flow already uses — no duplicated form schema or write logic — and each item's visibility independently re-checks the same authorization the original flow enforces (each domain's own `*AccessPolicyService` for the two Action-backed items — Client via `ClientCrmAccessPolicyService::canConvertLead()`, Payment via `PaymentAccessPolicyService::canRecordPayment()` — or the resource's own `canAccess()`/`canCreate()`, which is exactly what the real `CreateRecord` page authorizes against, for the seven link-backed items).

**Permanent completeness-guard test suite.** `tests/Feature/Governance/FirmWorkspaceCompletenessGuardTest.php` — a structural regression guard (not a business-logic test; each module already has its own dedicated suite) asserting: (1) the Firm panel's `Filament::getPanel('firm')` resolves and lists every Tier 1 resource plus `MatterResource`/`FirmIntegrationResource`/`PlaidItemResource`, so a future merge cannot silently shrink the sidebar back to "Dashboard + Matters"; (2) `FirmIntegrationResource`/`PlaidItemResource`/`ExpenseResource` (confirmed genuinely entitlement-gated, not merely role-gated) stay inaccessible for a zero-entitlement firm; (3) the panel's resource/page discovery is scoped to exactly `app/Filament/Firm/Resources`/`app/Filament/Firm/Pages` and nothing outside that namespace is ever registered; (4) no resource/page class exists yet for a real file-upload `Document` resource, any AI-facing surface, or a QuickBooks/Clio/LawPay/SMS/WhatsApp integration; (5) every Tier 1 module's manual Add/Create entry point exists and is reachable by an authorized role; plus dedicated coverage of the Quick Add menu itself (topbar component wiring, exact Action-class reuse, and role-gating parity with the original actions).

**Deliberately out of scope:** this item does not touch `tests/Feature/Security/RlsForceRollout/` — it is pure Filament panel-registration/authorization-surface testing, not tenant-isolation testing (RLS is covered exhaustively elsewhere).

---

## Status summary (quick reference)

| Category | Classification |
|---|---|
| Client/CRM | **BUILT (Tier 1)** — `ClientResource`, `ContactResource`, `FirmLeadResource` + Conflict Check UI on `MatterResource`; see §1 |
| Matters | **BUILT (Tier 3-A)** — `MatterCreationService` + "+ Add Matter"/"Open Matter" actions, read/view UI, relation-manager tabs, and Conflict Check UI; see §2 |
| Tasks/Deadlines | **BUILT (Tier 1)** — `TaskResource`, `DeadlineResource`; scheduler gap (overdue/missed refresh, reminder dispatch) remains a known follow-up, see §3 |
| Calendar | PARTIAL (internal) / BLOCKED (external sync) — unchanged, not in scope this mission |
| Documents/Files | **BLOCKED (no storage pipeline)** — Document Requests + Document Chase Rules **BUILT (Tier 1)**, see §5 |
| Forms/E-Signature | PARTIAL (no delivery UI) / PAID ADD-ON — unchanged, not in scope this mission |
| Time/Expenses | **BUILT (Tier 1)** — `TimeEntryResource`, `ExpenseResource` (entitlement-gated), `ExpenseReportPage`; see §6 |
| Billing (Invoices/Payment Plans) | **BUILT (Tier 2-A)** — Action-based only (`InvoiceResource`, `PaymentPlanResource`); no status/total field is ever form-editable; see §6 |
| Manual Client Payments | **BUILT (Tier 1)** — `PaymentResource` + `RecordPaymentAction`/`RecordClientPaymentAction`; see §6 |
| Trust/IOLTA | **BUILT (Tier 2-B)** — Action-based only, read-only ledger resources, nav hidden unless firm is trust-eligible; see §7 |
| Banking/Plaid | PAID ADD-ON (already shipped, keep gated) — unchanged |
| Reporting/Analytics | PARTIAL / mostly not built — `ExpenseReportPage` **BUILT (Tier 1)**, see §6/§9 |
| Audit Logs | PARTIAL (SecurityEvent — firm-scoped view now **BUILT**, see §11), PARTIAL-empty (TimelineEvent), PLATFORM ONLY (ImportAuditEvent) |
| Security (2FA/login/sessions) | **2FA self-service enrollment + Firm Security Activity page BUILT (Tier 3-D)**, opt-in only by deliberate design; `Required` mode remains un-toggleable; see §11 |
| Firm Team/Access | **BUILT (Tier 3-B)** — `FirmUserInvitationService` (invite/suspend/reactivate/remove) + `FirmUserResource` (List/View, Action-based mutation); see §12 |
| Firm Settings | **BUILT (Tier 3-C)** — `FirmSettingsPage`, incl. new address/phone columns; 2FA-mode fields deliberately excluded; see §13 |
| AI | **BLOCKED — governance without feature** — unchanged, not in scope this mission |
| Integrations (MS365/Google/QuickBooks) | Real code, not wired — do not claim sync works — unchanged, not in scope this mission |
| Communications | **BUILT (Tier 1, Consent)** — `CommunicationConsentResource` + Capture/Revoke actions; system email/consent READY, client dispatch/mailbox sync still **BLOCKED**; see §16 |
| Firm Workspace UI completeness (Quick Add + guard tests) | **BUILT (Tier 1)** — global "Quick Add" header menu (`App\Filament\Firm\Livewire\FirmTopbar`) + `tests/Feature/Governance/FirmWorkspaceCompletenessGuardTest.php`; see §18 |

**Mission status: Tiers 1, 2, and 3 are all complete.** Structurally-blocked categories (Documents/file-storage, AI, real Integrations sync, client-facing notification dispatch, external calendar sync) remain correctly un-built and out of navigation, per the mission's own "do not fake features" instruction — see each section above for the exact prerequisite.

---

## Proposed prioritized build plan (for confirmation before Phase 3+ implementation begins)

**Tier 1 — safe, real, high-value, ship first (no new backend invariants needed):**
1. **BUILT** — `ClientResource`, `ContactResource`, `FirmLeadResource` (+ Convert action) — §1
2. **BUILT** — `TimeEntryResource`, `ExpenseResource`, `ExpenseReportPage` — §6
3. **BUILT** — "Record Payment" action wired to `ManualPaymentService::submit()` — §6
4. **BUILT** — `TaskResource`, `DeadlineResource` (scheduler gap remains a known, disclosed follow-up) — §3
5. **BUILT** — Communication Consent UI — §16
6. **BUILT** — Document Requests + Document Chase UI (Chase-scheduler gap remains a known, disclosed follow-up) — §5
7. **BUILT** — Conflict Check UI — §1
8. **BUILT** — Relationship-manager wiring on Client/Matter views — §2
9. **BUILT** — Global "Quick Add" header menu + permanent completeness-guard test suite — §18

**Tier 2 — safe but requires Action-based (never form-bound) UI discipline:**
10. Invoice/PaymentPlan resources (Action-based only)
11. Trust/IOLTA read-only resources first, then Action-based mutation UI, then a Filament-layer firewall test

**Tier 3 — needs one piece of prerequisite backend work before UI is meaningful:**
12. **BUILT** — `MatterCreationService` (new) → "+ Add Matter" / "Open Matter" actions — §2
13. **BUILT** — `FirmUserInvitationService` (new) → `FirmUserResource` (invite/suspend/reactivate/remove) — §12
14. Firm Settings: singleton settings page (field-by-field gating decision needed first)
15. Firm-scoped `SecurityActivityPage` (SecurityEvent) — safe today, just needs building
16. 2FA enrollment + recovery flow **before** any UI implies 2FA is togglable

**Tier 4 — structurally blocked, needs a product/infra decision before scoping UI:**
17. Documents/Files real storage wiring (S3 disk decision)
18. AI real provider adapter
19. Client notification dispatch real transport
20. Email/mailbox real OAuth
21. External Calendar sync materialization
22. Integrations (MS365/Google) context-threading fix

**Tier 1 is now fully BUILT** (items 1–9 above; verified against the current working tree's `app/Filament/Firm/Resources/` — see the Status summary table and each numbered section for the exact resource/test-file pointers). The recommended next step is to confirm Tier 2 scope and sequencing (Invoices/Payment Plans, then Trust/IOLTA — both require Action-based-only UI discipline) before writing any further Filament code.
