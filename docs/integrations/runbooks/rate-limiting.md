# Runbook: Rate Limiting

## Symptom

A connection appears to be hitting a provider-side rate limit, or an operator wants to understand current rate-limit posture for a connection.

## Real source involved

`App\Integrations\Support\PerConnectionRateLimiter` (`app/Integrations/Support/PerConnectionRateLimiter.php`), `HealthStatus::RateLimited`, `HealthStateService::recordRateLimited()`.

## Honest capability statement — read this first

**There is no proactive rate limiter in production today.** `PerConnectionRateLimiter` exists, is namespaced correctly per `firm_integration_id` (never globally, never per-firm-alone — see [security-model.md](../security-model.md) §8), but is **not wired into any production call site**, confirmed by a repo-wide search at HEAD. The only place a "rate-limited" signal appears in real behavior today is the **reactive** TestProvider simulation responding after the fact — there is no production mechanism that proactively holds back a request before it would exceed a budget.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist.

## Approved interface

`HealthStateService::summaryFor()` / `summariesForFirm()` — read-only health-state inspection, showing `HealthStatus::RateLimited` when a rate-limit response was reactively recorded. There is no operator tool to view or adjust a rate-limit budget, because no live budget exists to view.

## Steps

1. Confirm the health state shows `RateLimited` via `PlatformFirmIntegrationDetailPage` or the firm's own connection detail view.
2. Because there is no proactive limiter, a `RateLimited` health state reflects an actual rejected request from the provider — not a preemptive framework-side throttle. Treat it as a live signal from the provider, not framework noise.
3. Allow the framework's own backoff (`integrations.health.backoff_base_seconds`/`backoff_max_seconds`) to govern retry cadence — this is what those config keys exist for, and is the only mitigation currently in effect.
4. Do not attempt to manually construct a workaround rate limiter at the operator level — that would require an application-code change, not an operational action.

## Prohibited actions

Never claim or imply a rate limit is being proactively enforced when investigating an incident — it is not, and saying otherwise would mislead whoever reads the investigation record.

## Evidence to capture

Firm id, connection id, provider, timestamp(s) of rate-limited responses, frequency/pattern.

## Escalation condition

**Before any real provider is onboarded**, this gap must be explicitly decided — wire `PerConnectionRateLimiter` into the actual dispatch path, or explicitly accept the reactive-only posture as sufficient for that provider's real-world rate-limit behavior. This is a product/engineering decision, not something this runbook can resolve operationally. See [known-limitations.md](../known-limitations.md).

## Recovery verification

Health state returns to `Healthy` once the provider stops returning rate-limit responses and a subsequent request succeeds (`HealthStateService::recordSuccess()`).
