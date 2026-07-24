# Runbook: OAuth Refresh — Transient Failure

## Symptom

`ProviderConnectionService::refreshConnectionToken()` fails due to a transient condition (network timeout, provider 5xx, rate limit) — distinct from an invalid-grant rejection (see [oauth-refresh-invalid-grant.md](oauth-refresh-invalid-grant.md)), where the provider has not rejected the grant itself, just the specific attempt.

## Real source involved

`App\Integrations\Jobs\RefreshIntegrationToken` (`ShouldQueue`), `SanitizedProviderHttpException`, `HealthStateService::recordProviderError()` / `recordRateLimited()`.

## Diagnosis

`SanitizedProviderHttpException` (sanitized — never carries raw provider response bodies that might contain secret-shaped data) distinguishes the failure category. `IntegrationCredentialService::withRefreshLock()` guards concurrent refresh attempts for the same connection, so a transient failure during a refresh attempt does not corrupt concurrent state.

## Required role

Platform-plane investigation only (SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist) — this is not a firm-user-actionable scenario; the job itself owns retry behavior.

## Approved interface

`App\Filament\Actions\Platform\NudgeIntegrationQueueAsSupportAction` (`PlatformFirmIntegrationBoundedAccessService::nudgeQueue()`) dispatches an immediate `OutboxDispatchJob`/`SyncRetryPollJob` tick for the firm — the exact same dispatch shape the scheduler already performs on its normal cadence, never a new dispatch mechanism. This is the closest operator-facing lever to "retry now" for this framework broadly; there is no dedicated "force a token refresh retry right now" action separate from the normal job retry mechanism.

## Steps

1. Confirm the failure category is transient (network/5xx/rate-limit), not invalid-grant — check `SanitizedProviderHttpException`'s sanitized category field.
2. Confirm the connection's health state via `HealthStateService::summaryFor()` — a single transient failure should show `Degraded`, not `Unavailable`, per the configured `degraded_after_failures`/`unavailable_after_failures` thresholds (see [configuration.md](../configuration.md) items 14–15).
3. If the job's own retry/backoff has not yet run, no action is typically needed — allow the job's built-in retry to proceed.
4. If urgency requires it and the operator has appropriate access, use `NudgeIntegrationQueueAsSupportAction` to trigger an immediate retry tick rather than waiting for the next scheduled cadence.

## Prohibited actions

Never manually construct or replay a refresh request outside the framework's own job/service path. Never decrypt the credential to inspect it as part of diagnosing a transient failure — the failure category comes from the sanitized exception, not from the credential itself.

## Evidence to capture

Firm id, connection id, `SanitizedProviderHttpException` category, health-state transition observed, whether the issue self-resolved on the next scheduled retry.

## Escalation condition

Sustained transient failures across multiple retry cycles for the same connection (health state reaching `Unavailable`) should be escalated — either the provider is genuinely down (see [provider-outage.md](provider-outage.md)) or something in the framework's own retry/backoff configuration is misbehaving.

## Recovery verification

Health state returns to `Healthy` (`HealthStateService::recordSuccess()`) on the next successful refresh.
