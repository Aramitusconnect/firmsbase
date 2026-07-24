# Runbook: Provider Outage

## Symptom

A provider is unreachable or consistently erroring across many/all connections to it, distinct from an isolated per-connection issue.

## Real source involved

`HealthStateService` (`recordProviderError()`, `summaryFor()`, `summariesForFirm()`), `IntegrationConnectionHealth` model, `HealthStatus` enum (`ProviderOutage` case exists specifically for this scenario).

## Diagnosis

`HealthStatus::ProviderOutage` is a defined vocabulary case. There is no cross-firm aggregation view of health state by provider today beyond what `PlatformIntegrationOverviewPage`'s per-firm snapshot table (`integration_platform_overview_summaries`) exposes filtered manually by an operator — there is no dedicated "show me every connection currently unhealthy for provider X" query or alert. This is a disclosed gap, not an oversight: see [known-limitations.md](../known-limitations.md) (observability is queryable-only, never alerting, anywhere in this framework).

## Required role

Platform-plane: SuperAdmin/PlatformAdmin/ImplementationSpecialist for cross-firm visibility (coarse role gate only — `PlatformIntegrationOverviewPage` never requires a support-access session). Per-firm remediation: SupportAgent+ with an active support-access session for the specific firm.

## Approved interface

`PlatformIntegrationOverviewPage` — filterable by health state (`health_summary_state`) — is the closest cross-firm visibility tool, manually cross-referenced by an operator against a suspected provider outage; it is not provider-filterable directly (the summary table is one row per firm, not per connection/provider — see [operations-superadmin.md](../operations-superadmin.md) §2).

## Steps

1. Confirm the pattern (multiple firms/connections to the same provider showing degraded/unavailable health) via manual review of `PlatformIntegrationOverviewPage`, filtered by health state.
2. Do not nudge queues repeatedly against a genuinely outaged provider — this would waste retry budget and could compound the provider's own load; nudging only makes sense once the provider is believed to have recovered.
3. Allow the framework's own backoff (`integrations.health.backoff_base_seconds`/`backoff_max_seconds`) to govern retry cadence during the outage — this is what those config keys exist for.
4. Once the provider is confirmed recovered, `NudgeIntegrationQueueAsSupportAction` may be used per-firm to accelerate recovery rather than waiting for the natural backoff/scheduled cadence to catch up.

## Prohibited actions

Never disable a provider's registration (`config/integrations.php`) as a live operational response to an outage without engineering involvement — the registry is a deploy-time configuration surface, not an incident-response lever, and editing it requires a code change and deploy.

## Evidence to capture

Provider key, affected firm/connection count (manually tallied — no automated count exists), health-state transitions observed, provider's own status page/communication if available (external to this framework).

## Escalation condition

Any suspected provider outage should be escalated to engineering/product for provider-relationship follow-up — this framework has no automated incident-detection for this scenario (see [known-limitations.md](../known-limitations.md), observability section).

## Recovery verification

Affected connections' health states return to `Healthy` (`HealthStateService::recordSuccess()`), confirmed via `PlatformIntegrationOverviewPage`.
