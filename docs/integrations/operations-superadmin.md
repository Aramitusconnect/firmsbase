# SuperAdmin / Platform Oversight Operations

## 1. Two authority planes

This framework has two distinct authority planes (Checkpoint 11):

- **Firm-plane**: FirmUser actor, gated by `IntegrationAccessPolicyService` + `IntegrationEntitlementPolicyService`. See [operations-firm.md](operations-firm.md).
- **Platform-plane**: PlatformAdmin actor, routed through `PlatformStaffAccessPolicyService::canAccessIntegrationOversight()` and, for per-firm reads/actions, `App\Services\PlatformFirmIntegrationBoundedAccessService`.

## 2. Filament pages

| Page | Scope | Access check |
|---|---|---|
| `PlatformIntegrationOverviewPage` | Always-visible, cross-firm, aggregate/sanitized overview. Reads only the no-RLS `integration_platform_overview_summaries` snapshot table — never a live cross-firm query against a FORCE-RLS tenant table. | Coarse role gate only (`canAccessIntegrationOversight()`) — never requires a support-access grant. |
| `PlatformFirmIntegrationsPage` | Per-firm list within platform oversight. | `canAccessIntegrationOversight()`, then `PlatformFirmIntegrationBoundedAccessService` per-firm gating. |
| `PlatformFirmIntegrationDetailPage` | Per-firm connection detail. | Same as above. |

`PlatformIntegrationOverviewPage` deliberately declares **no public properties at all** — every read happens fresh inside the table's `records()` closure, which re-resolves the acting `PlatformAdmin` and re-calls `IntegrationPlatformOversightReadService::overviewSummaries()` on every render, never caching a value on `$this` between requests.

Filterable by firm (searchable select against `firm_uuid`), sync-outcome status (`last_sync_outcome`), and health (`health_summary_state`). **Not filterable by provider** — `integration_platform_overview_summaries` is one row per firm, not per connection/provider, and carries no provider-level column; this is a schema limitation, not an oversight.

## 3. The bounded-access chokepoint

`App\Services\PlatformFirmIntegrationBoundedAccessService` is the single chokepoint every Checkpoint 11 Filament page/action goes through for (a) every per-firm read and (b) every mutating action against a firm's integration data:

1. `PlatformStaffAccessPolicyService::canAccessIntegrationOversight()` — coarse, role-level gate, checked first.
2. For roles that pass (1) but aren't unconditionally-trusted ceiling roles (SuperAdmin, PlatformAdmin, ImplementationSpecialist) — i.e. SupportAgent — every per-firm read or action additionally requires an active, governed `SupportAccessSession` scoped to the exact target firm.

## 4. Support-access governance gap closures (built as new code in this service, not by editing pre-existing files)

- `requestSupportAccess()` invokes both `SupportAccessRequestService::request()` **and** `logNotification()` as two explicit sequential calls (the pre-existing service never called `logNotification()` itself).
- `enterSupportAccessSession()` verifies the session-starter is the original requester (`$request->requested_by === $admin->id`) before calling `start()` — the pre-existing session service performed no such check.
- `leaveSupportAccessSession()` / `revokeSupportAccessSession()` add an idempotency guard the pre-existing `end()`/`revoke()` methods lacked.

None of `SupportAccessRequestService`, `SupportAccessSessionService`, `SupportAccessPolicyService` were modified to add these — they remain byte-for-byte untouched; this service is their first real caller for the integration domain.

## 5. Audit attribution — read this before investigating a support-access event

Two `security_events` categories are relevant, and they are **not interchangeable**:

- `platform_integration_oversight` (`PlatformFirmIntegrationBoundedAccessService::SECURITY_EVENT_CATEGORY`) — the **correct, authoritative** category for platform-integration-oversight attribution.
- `support_access` (written by the pre-existing `SupportAccessPolicyService`) — carries a known misattribution for cross-actor revokes: `logSessionAudit()` names the session owner as the actor, not the actual revoker. **Investigators must use `platform_integration_oversight`, not `support_access`, when attributing a revoke action** — see [runbooks/support-session-revoke.md](runbooks/support-session-revoke.md).

## 6. Operator actions available today

Nudging a stuck queue as a support action: `App\Filament\Actions\Platform\NudgeIntegrationQueueAsSupportAction`. Requeue actions on outbox events/sync items (governed by `IntegrationRequeueAuditLogger`). Support-access request/enter/leave/revoke (above). See the `runbooks/` tree for the specific operational playbooks and their explicit boundaries.

## 7. What does not exist

No global "disable integrations for this firm" switch beyond entitlement revoke (see [feature-flag-rollout.md](feature-flag-rollout.md)). No live, per-firm cross-tenant query tool — the overview page is intentionally restricted to the pre-computed, sanitized snapshot table for exactly this reason.
