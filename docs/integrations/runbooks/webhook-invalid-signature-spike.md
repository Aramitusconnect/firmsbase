# Runbook: Webhook Invalid-Signature Spike

## Symptom

An elevated rate of `401 {"status":"rejected"}` responses from `POST /webhooks/integrations/{provider}`, suspected to be signature-verification failures specifically.

## Real source involved

`InboundWebhookSignatureVerifier::verify()`, `InboundWebhookController`, `InboundWebhookAuditLogger` (platform-only, never exposed to the caller).

## Diagnosis

Because of the collapse-to-false response shape (see [webhooks.md](../webhooks.md) §3), the wire response for a signature failure is byte-identical to routing-resolution failure, malformed payload, and every other pre-verification rejection — **the HTTP response alone cannot distinguish these**. The only place the specific cause is distinguishable is `InboundWebhookAuditLogger`'s internal, platform-only logging.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session (if the spike is isolated to one firm's connection) or SuperAdmin/PlatformAdmin/ImplementationSpecialist (if investigating a suspected cross-firm/provider-wide pattern).

## Approved interface

Application log review correlated to `InboundWebhookAuditLogger`'s entries — there is no dedicated Filament UI surface for webhook rejection counts or rates today (a disclosed observability gap — see [known-limitations.md](../known-limitations.md); this is queryable via logs, never alerted on).

## Steps

1. Correlate the spike's timing/volume against `InboundWebhookAuditLogger` entries to confirm the rejection cause is specifically signature mismatch, not routing/malformed-payload/replay.
2. If isolated to one connection/firm: consider whether the firm's provider-side webhook secret was recently rotated out of sync with `integration_credentials` — `InboundWebhookSignatureVerifier` supports a bounded 2-candidate secret rotation window, so a rotation older than that window would explain a sustained failure.
3. If widespread across many firms/connections for the same provider: consider whether the provider itself changed its signing scheme or key — this would require an engineering/provider-relationship investigation, not an operator-level fix.
4. If the spike looks like malicious probing (many distinct routing tokens, no legitimate provider correlation): this is expected, safe behavior — the endpoint is designed to reject unrecognized/forged traffic with the identical, information-non-leaking response shape. No panic response needed; the IP-keyed `throttle:60,1` middleware bounds the request rate regardless.

## Prohibited actions

Never weaken or bypass signature verification "temporarily" to let traffic through during triage — this is the framework's core webhook security boundary. Never decrypt or directly inspect a connection's webhook signing secret to "check" it manually — use the credential rotation path (`IntegrationCredentialService::rotate()`) if a genuine resync is needed, never an ad hoc read.

## Evidence to capture

Timestamp range, affected routing tokens (never log raw secret material), rejection volume, whether isolated to one connection or widespread.

## Escalation condition

A widespread, sudden spike with no clear rotation/config explanation should be escalated to engineering as a possible provider-side incident or a possible probing/attack pattern worth deeper log analysis.

## Recovery verification

Rejection rate returns to baseline; if a rotation was the cause, confirm the new secret was correctly stored via `IntegrationCredentialService::rotate()` and that subsequent webhook deliveries verify successfully.
