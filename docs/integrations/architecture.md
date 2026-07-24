# Architecture

System shape of `app/Integrations/` at HEAD `c34f94c3752ad6f53b0f39de3f279da1f8b4ba13`. Every count below was independently verified against source at that commit, not carried forward from an earlier checkpoint's report unchecked.

## 1. Tables — 16, not 17

The framework owns exactly **16 tables**, all created by `database/migrations/2026_09_*` (the framework's migration range):

| # | Table | Migration | RLS |
|---|---|---|---|
| 1 | `integration_providers` | `2026_09_01_010001` | none (platform catalog) |
| 2 | `firm_integrations` | `2026_09_02_020001` / `...020002` | FORCE RLS |
| 3 | `integration_credentials` | `2026_09_03_030001` / `...030002` | FORCE RLS |
| 4 | `integration_oauth_states` | `2026_09_04_040001` / `...040002` | FORCE RLS + self-lookup carve-out |
| 5 | `integration_sync_runs` | `2026_09_05_050001` / `...050002` | FORCE RLS |
| 6 | `integration_sync_items` | `2026_09_05_051001` / `...051002` | FORCE RLS |
| 7 | `integration_external_mappings` | `2026_09_05_052001` / `...052002` | FORCE RLS |
| 8 | `integration_sync_cursors` | `2026_09_05_053001` / `...053002` | FORCE RLS |
| 9 | `integration_conflicts` | `2026_09_05_054001` / `...054002` | FORCE RLS |
| 10 | `integration_outbox_events` | `2026_09_05_055001` / `...055002` | FORCE RLS |
| 11 | `integration_webhook_routing_index` | `2026_09_06_060001` | none (platform, pre-tenant-context routing pointer) |
| 12 | `integration_webhook_receipts` | `2026_09_06_060002` | none (platform, no `firm_id` column ever) |
| 13 | `integration_inbound_webhook_events` | `2026_09_06_060003` / `...060004` | FORCE RLS |
| 14 | `integration_connection_health` | `2026_09_07_070001` / `...070002` | FORCE RLS |
| 15 | `integration_usage_records` | `2026_09_08_080001` / `...080002` | FORCE RLS |
| 16 | `integration_platform_overview_summaries` | `2026_09_09_090001` | none (platform, no-RLS aggregate snapshot) |

**Footnote — why not 17.** `integration_degradation_modes` (`database/migrations/2026_07_25_900007_create_integration_degradation_modes_table.php` and its companion seeder `...900009`) is **not** part of this framework. It predates this framework's own migration range by five weeks, its model (`App\Models\IntegrationDegradationMode`) lives outside `app/Integrations/Models/`, it is owned by a separate, pre-existing `App\Services\IntegrationDegradationRegistryService`, and it is explicitly listed as a protected Phase 16 file in `tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php`. It shares only a naming prefix with this framework. Counting it (as an earlier checkpoint's own report did) overstates this framework's footprint at 17 tables; the correct count is 16.

Of the 16, **12 are DirectTenant tables under FORCE ROW LEVEL SECURITY** (one canonical policy shape plus one documented deviation on `integration_oauth_states`); 4 are platform-owned tables with no `firm_id` column and no RLS policy (`integration_providers`, `integration_webhook_routing_index`, `integration_webhook_receipts`, `integration_platform_overview_summaries`). See [rls-and-tenancy.md](rls-and-tenancy.md) for the full policy detail.

## 2. Code inventory

| Category | Count | Location |
|---|---|---|
| Migrations | 31 | `database/migrations/2026_09_*` |
| Models (in-domain) | 15 | `app/Integrations/Models/` |
| Models (integration-specific, outside the domain dir) | 1 | `app/Models/IntegrationPlatformOverviewSummary.php` |
| Contracts | 9 | `app/Integrations/Contracts/` |
| Providers registered | 1 (TestProvider) | `app/Integrations/Providers/TestProvider/` |
| DTOs | 14 | `app/Integrations/Data/` |
| Services (in-domain) | 22 | `app/Integrations/Services/` |
| Services (integration-specific, outside the domain dir) | 4 confirmed (`IntegrationEntitlementPolicyService`, `PlatformFirmIntegrationBoundedAccessService`, `IntegrationPlatformOversightReadService`, `IntegrationPlatformOverviewSummaryService`) | `app/Services/` |
| Jobs | 7 (6 in `app/Jobs/`, 1 in `app/Integrations/Jobs/`) | see [jobs-and-scheduler.md](jobs-and-scheduler.md) |
| Console commands | 4 | `app/Console/Commands/` |
| Routes | 3 (2 OAuth + 1 webhook intake) | `routes/web.php`, `routes/webhooks.php` |
| Controllers | 1 (`InboundWebhookController`) confirmed under `app/Integrations/`; OAuth initiate/callback routes are closure- or controller-routed in `routes/web.php` | `app/Integrations/Http/Controllers/` |
| Filament files | 21 | `app/Filament/Firm/Resources/FirmIntegrationResource*`, `app/Filament/Pages/Platform*`, `app/Filament/Actions/Platform/NudgeIntegrationQueueAsSupportAction.php` |
| Tests | 119 files under `tests/Feature/Integrations` + `tests/Unit/Integrations` (method count varies by counting methodology; do not treat any single number as more authoritative than a fresh count at the commit in question) | `tests/Feature/Integrations/`, `tests/Unit/Integrations/` |
| Env vars | 19, all `INTEGRATIONS_*`, all funneled through `config/integrations.php` | see [configuration.md](configuration.md) |
| Scheduler entries | 4 | `bootstrap/app.php`, `withSchedule()` |

The framework also depends on, but does not own, shared platform infrastructure: `App\Services\EntitlementService` / `module_catalog` (entitlement gating), `App\Services\PlatformStaffAccessPolicyService` (the coarse platform-staff role gate, via `canAccessIntegrationOversight()`), `App\Services\TimelineEventRecorder` and the `security_events`/`timeline_events` tables (audit trail), `App\Support\TenantAwareJobContext` and `TenantContextService` (tenant-context propagation into jobs), and `App\Models\SupportAccessRequest`/`SupportAccessSession` (governed support access).

## 3. Request/data lifecycle

1. **Authorization**: a firm action is gated by `App\Integrations\Policies\FirmIntegrationPolicy` (the first standard Laravel `Policy` class in this codebase), which delegates the actual role check to `IntegrationAccessPolicyService` (non-financial tier) or `FinancialIntegrationAccessPolicyService` (financial tier, deliberately kept separate, no real financial provider registered).
2. **Entitlement**: `IntegrationEntitlementPolicyService` checks the `integration` `module_catalog` code via the existing `EntitlementService` — no new entitlement mechanism, reuse of the pre-existing `firm_entitlements` machinery. Checked before role/permission per the frozen ordering.
3. **Connection**: `ProviderConnectionService` creates/manages a `FirmIntegration` row, resolving the concrete provider class through `App\Integrations\Core\ProviderRegistry` (fails closed — `UnknownProviderException` — for any provider key not present in `config('integrations.providers')`).
4. **Credentials**: `IntegrationCredentialService` is the sole writer/reader boundary for `IntegrationCredential` rows — no other class decrypts a credential.
5. **Capability dispatch**: provider capabilities are discovered via `instanceof` checks against the 9 contracts in `app/Integrations/Contracts/` (e.g. `SupportsOAuthContract`, `SupportsWebhooksContract`, `SupportsPullSyncContract`) — never a hardcoded provider-key branch outside the provider's own class.
6. **Outbound work**: queued through `IntegrationOutboxEventService` (outbox pattern) or directly via `PullSyncJob`/`PushSyncJob`, tracked through `IntegrationSyncRun`/`IntegrationSyncItem`/`IntegrationSyncCursor`.
7. **Inbound webhooks**: `InboundWebhookController` → `WebhookConnectionResolverService` → `InboundWebhookSignatureVerifier` → `InboundWebhookReceiptService` → `InboundWebhookEventService`. See [webhooks.md](webhooks.md).
8. **Health**: `HealthStateService` records per-connection health transitions (`IntegrationConnectionHealth`), read by both firm and platform UI.
9. **Audit**: `TimelineEventRecorder` plus category-specific audit loggers (`InboundWebhookAuditLogger`, `IntegrationRequeueAuditLogger`, `RetentionSweepAuditLogger`) write to the existing `timeline_events`/`security_events` tables — no new audit-storage mechanism.
10. **Usage recording**: `IntegrationUsageRecorderService` exists and is fully implemented, but has **zero production call sites** — it is dead code today. `integration_usage_records` stays permanently empty in production. See [known-limitations.md](known-limitations.md).
11. **Firm UI**: `FirmIntegrationResource` (Filament) plus `IntegrationUsagePage`. See [operations-firm.md](operations-firm.md).
12. **Platform oversight UI**: `PlatformIntegrationOverviewPage`, `PlatformFirmIntegrationsPage`, `PlatformFirmIntegrationDetailPage`, gated through the single chokepoint `PlatformFirmIntegrationBoundedAccessService`. See [operations-superadmin.md](operations-superadmin.md).

## 4. Known duplicate-looking abstractions (intentional, not accidental)

- `IntegrationAccessPolicyService` (non-financial tier) vs. `FinancialIntegrationAccessPolicyService` (financial tier) — deliberately kept separate per the frozen spec; no financial provider exists to exercise the financial-tier class today.
- `recordOnce()` exists on two unrelated services: `IntegrationOutboxEventService`'s idempotent-write helper, and `IntegrationUsageRecorderService::recordOnce()` (unwired). Same method name, unrelated purposes — a naming collision worth knowing about, not a duplicated implementation.

## 5. Deployment status

This framework has never been deployed. See [README.md](README.md#deployment-authorization) and [runbooks/integration-deployment-checklist.md](runbooks/integration-deployment-checklist.md).
