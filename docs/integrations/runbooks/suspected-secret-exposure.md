# Runbook: Suspected Secret Exposure

## Symptom

Suspicion that an `integration_credentials` secret (OAuth token, API key, webhook signing secret) has been exposed — e.g. appeared in a log line, was pasted somewhere it shouldn't be, or a firm reports suspicious provider-side activity suggesting their credential leaked.

## Real source involved

`IntegrationCredentialService` (sole writer of `integration_credentials`) — specifically `rotate()`, `revoke()`, `store()`/`replace()`.

## Global rule — read this before doing anything else

**Credentials are never decrypted "to check" them.** There is no ad hoc "view secret" surface anywhere in this framework, and none may be built without a separate, explicitly authorized checkpoint. This applies even during an active suspected-exposure investigation — the response to a suspected exposure is rotation/revocation, never inspection of the suspected-exposed value itself. See [security-model.md](../security-model.md) §1.

## Required role

Platform-plane: SuperAdmin/PlatformAdmin/ImplementationSpecialist, or SupportAgent+ with an active support-access session scoped to the affected firm. This is a security-sensitive action and should not be delegated below that ceiling.

## Approved interface

`IntegrationCredentialService::revoke(FirmIntegration $connection, IntegrationCredential $credential, string $reason)` — immediate revocation. `rotate()` — same connection, new credential material (requires the firm to complete a fresh connect/reauthorize flow to actually populate a new value; rotation is not a self-generating action for OAuth-based credentials). There is **no standalone credential-rotation operator tool** beyond these connection-level actions — rotation happens only via `rotate()` or reconnect/disconnect at the connection level, never via a dedicated secret-management UI.

## Steps

1. **Do not attempt to confirm the suspicion by decrypting/viewing the credential.** Treat the suspicion as actionable on its own merits (source of the report, plausibility) without that step.
2. Revoke the credential immediately via `IntegrationCredentialService::revoke()`, with a `reason` documenting the suspected exposure.
3. Instruct the firm to reconnect (fresh OAuth flow or new API key entry) to establish a new, unexposed credential.
4. If the exposure vector is understood (e.g. a specific log line, a specific external system), address that vector directly — e.g. purge the offending log line through the appropriate log-retention/redaction process (outside this framework's scope) — do not treat rotation alone as resolving the exposure vector itself.

## Known, disclosed gap to be aware of during this scenario

`IntegrationCredentialService::reEncrypt()`'s ordering relative to key rotation carries a known, disclosed caveat in its own docblock (`app/Integrations/Services/IntegrationCredentialService.php:300`). **Do not work around this ordering gap ad hoc as part of an exposure response** — if it becomes relevant (e.g. exposure is suspected to involve the application's own encryption key rather than a single credential), escalate to engineering rather than improvising a fix.

## Prohibited actions

Decrypting a credential to "confirm" exposure. Logging or otherwise recording any decrypted or plaintext secret material as part of the investigation evidence. Building or using any unofficial "view secret" tool or database query.

## Evidence to capture

Firm id, connection id, credential id, suspected exposure vector, revocation timestamp, reconnect completion timestamp. **Never the secret value itself, in any form.**

## Escalation condition

Every suspected secret exposure is a security escalation by default — notify whoever owns security incident response for this product, not just an operational log entry. If the suspected exposure vector is systemic (e.g. a logging misconfiguration that could affect many connections, not just one), escalate immediately rather than handling firm-by-firm.

## Recovery verification

Old credential confirmed `revoked` status; new credential confirmed active and functioning (a real sync/webhook operation succeeds using it); exposure vector addressed or confirmed contained.
