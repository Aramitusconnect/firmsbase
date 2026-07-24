# Runbook: OAuth Callback Failure

## Symptom

A firm user completes a provider's OAuth consent screen but the callback fails — connection never reaches `Active` status.

## Real source involved

`App\Http\Controllers\Integrations\OAuthConnectionController::callback` → `ProviderConnectionService::completeOAuthCallback()` → `IntegrationOAuthStateService::resolveAndConsume()`. See [oauth.md](../oauth.md) §3.

## Diagnosis

Distinguish by exception thrown (visible in application logs, not exposed verbatim to the end user):

| Exception | Meaning |
|---|---|
| `OAuthStateNotFoundException` | The `state` value doesn't resolve to any row — expired-and-swept, forged, or from a different environment |
| `OAuthStateExpiredException` | Row found but past its expiry window |
| `OAuthStateAlreadyConsumedException` | Row already consumed — likely a duplicate callback (double-click, browser back/forward) |
| `OAuthRedirectUriMismatchException` | `redirect_uri` re-validation at claim time failed — possible tampering, or a genuine app configuration change between initiate and callback |
| `OAuthAccountMismatchException` | The acting `FirmUser`'s membership no longer matches the FirmUser that initiated the flow — role/membership changed mid-flow |

## Required role

Firm-plane: the firm user experiencing the failure retries themselves (Connect/configure ceiling: FirmOwner, Attorney — see [security-model.md](../security-model.md)). Platform-plane investigation: SupportAgent+ with an active support-access session for the firm, or SuperAdmin/PlatformAdmin/ImplementationSpecialist.

## Approved interface

No dedicated operator tool exists for inspecting a specific failed OAuth state row's contents. An operator can confirm a connection's current `ConnectionStatus` via `PlatformFirmIntegrationDetailPage` (platform oversight) or the firm's own `FirmIntegrationResource` view page.

## Steps

1. Confirm which exception was thrown (application log correlation by timestamp/firm).
2. For `NotFound`/`Expired`/`AlreadyConsumed`: instruct the user to restart the connect flow from the beginning (`initiate` route) — these are expected outcomes of a stale or replayed callback, not a defect.
3. For `RedirectUriMismatch`/`AccountMismatch`: escalate — these indicate either a configuration issue or a genuine security-relevant mismatch and should not be waved through with a retry alone.

## Prohibited actions

Never decrypt or inspect `integration_oauth_states.verifier_ciphertext` "to check" it — there is no legitimate operational reason to read PKCE verifier material, and no tool exists to do so. Never bypass RLS to look up another firm's OAuth state row.

## Evidence to capture

Firm id, connection id, exception class, timestamp, whether the user reports having clicked the callback link more than once.

## Escalation condition

Any `RedirectUriMismatch` or `AccountMismatch` occurrence should be escalated to engineering — both indicate the security-relevant paths are functioning as designed (rejecting something), but repeated occurrences for the same firm/connection warrant investigation into why the mismatch is happening at all.

## Recovery verification

Connection reaches `ConnectionStatus::Active` after a clean restart of the connect flow; `integration_oauth.authorization_initiated`/completion timeline events recorded for the successful attempt.
