# Runbook: OAuth Refresh — Invalid Grant

## Symptom

`ProviderConnectionService::refreshConnectionToken()` (invoked via `App\Integrations\Jobs\RefreshIntegrationToken`) fails because the provider has rejected the refresh token itself (revoked at the provider, expired, or the grant was invalidated) — not a transient network issue.

## Real source involved

`ProviderConnectionService::refreshConnectionToken(FirmIntegration $connection): OAuthCallbackResult`, `ProviderConnectionService::markRefreshExhausted(FirmIntegration $connection, string $category)`, `App\Integrations\Jobs\RefreshIntegrationToken`. See [oauth.md](../oauth.md) §4.

## Diagnosis

This is distinct from [oauth-refresh-transient-failure.md](oauth-refresh-transient-failure.md): an invalid-grant failure is **not retryable** by definition — the provider has explicitly said this refresh token no longer works, so retrying the same refresh token will fail identically every time. `markRefreshExhausted()` transitions the connection (expected end state: `ConnectionStatus::ReauthorizationRequired`, the one case added specifically for this scenario).

## Required role

Firm-plane: FirmOwner, Attorney (connect/configure ceiling). Platform-plane: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist for read-only investigation.

## Approved interface

Firm user: reconnect via the standard `initiate` OAuth route (a full new consent flow — there is no "just refresh the grant" shortcut). Platform operator: read-only visibility into the connection's status via `PlatformFirmIntegrationDetailPage`; no operator action forces a refresh retry for an invalid-grant case, because retrying would be pointless — the grant is dead.

## Steps

1. Confirm the connection's status is `ReauthorizationRequired` (or is heading there).
2. Confirm via logs that the refresh failure was an invalid-grant response from the provider, not a transient error (see the transient-failure runbook if ambiguous).
3. Instruct the firm user to reconnect — a fresh OAuth consent flow, not a "retry" of the old grant.
4. No credential rotation/inspection is needed or permitted — the old credential is simply superseded by the new one `IntegrationCredentialService` writes when the fresh connect flow completes.

## Prohibited actions

Never decrypt the old (dead) refresh token to "verify" it's actually invalid — the provider's rejection is authoritative; there is no legitimate reason to inspect it. Never attempt to manually re-issue or repair a refresh token — this framework has no such mechanism and building one is out of scope.

## Evidence to capture

Firm id, connection id, provider, timestamp of the invalid-grant response, whether this is a first occurrence or a repeat for this connection.

## Escalation condition

A pattern of invalid-grant failures across many connections for the same provider around the same time may indicate a provider-side incident (e.g. a bulk token revocation) rather than isolated per-user issues — escalate to engineering if more than an isolated handful occur close together. TestProvider makes no real network calls, so this scenario cannot occur against it today — this runbook is written for the eventual real-provider case.

## Recovery verification

Connection returns to `ConnectionStatus::Active` after the fresh reconnect completes.
