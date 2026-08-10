# Mission 1 (Canonical Reconstruction) — Domain & Security Boundary Architecture: Threat Model

Rebuilt from scratch against the real, canonical FirmsVault application
(`fix/real-staging-and-firm-self-registration` @ `02ce3eb`) — not the
earlier, stale-`main`-based Mission 1 attempt. Full extreme-hardening
controls (WebAuthn, IAM least-privilege, CloudTrail/GuardDuty, real CSP,
secret rotation, etc.) remain Mission 1B's scope.

## Assets

- Firm-user sessions (`web` guard, `app.firmsvault.com`)
- ClientPortalUser sessions (`client` guard, `client.firmsvault.com`) — distinct from the tenant `Client` record, which is never itself a session principal
- Platform Admin sessions (`platform_admin` guard, `admin.firmsvault.com`)
- TOTP secrets and MFA recovery codes (`PlatformAdmin.two_factor_secret`/`two_factor_recovery_codes`, encrypted)
- OAuth tokens and PKCE verifiers for Google Workspace / Microsoft 365 / Plaid (`integration_oauth_states`, `integration_credentials`, encrypted, DB-keyed not session-keyed)
- Webhook signing secrets and inbound webhook receipts (`integration_provider_webhook_subscriptions`, `integration_webhook_receipts`)
- Client documents, tenant data, payment/Trust/Accounting data (FORCE RLS, unchanged by this mission)
- Future MyAttorney/public prospect sessions (not built — reserved hostname only)

## Trust boundaries

```
Internet → firmsvault.com (marketing, public/indexable)
Internet → app.firmsvault.com (Firm, authenticated, real MFA optional)
Internet → client.firmsvault.com (Client Portal, ClientPortalUser-authenticated)
Internet → admin.firmsvault.com (Platform Admin, real TOTP MFA required)
Internet → myattorney.firmsvault.com (reserved placeholder, public/untrusted by design)
Internet → api.firmsvault.com (reserved, answers nothing)
ALB → ECS (container, plain HTTP internally — TLS terminates at the ALB)
ECS → PostgreSQL (RLS-enforced tenant boundary, unchanged by this mission)
ECS → AWS services (Secrets Manager, S3 — unchanged)
ECS → Google Workspace / Microsoft 365 / Plaid (real OAuth token exchange, PKCE)
Providers → routes/webhooks.php (inbound webhook ingress, host-agnostic by design)
```

## Threats

### 1. Cookie leakage
**Control:** Each panel's session cookie is host-only (`session.domain`
forced to `null` — `ConfigurePanelSessionCookie`), `__Host-` prefix
applied whenever `session.secure` is true.
**Tests:** session-cookie isolation matrix (see final report §12).
**Residual risk:** Low.

### 2. Session confusion
**Control:** Three distinct guards (`web`/`client`/`platform_admin`),
three distinct identity models (`User`/`ClientPortalUser`/
`PlatformAdmin`), three distinct panels with independent
`authMiddleware` stacks — none of this required modification, only
domain-binding on top of it.
**Residual risk:** Low.

### 3. Session fixation
**Control:** Unchanged — Filament's stock `AuthenticateSession`
middleware, present on all three panels before and after this mission.
**Residual risk:** Low, inherited from Filament's own defaults.

### 4. CSRF
**Control:** Unchanged — Filament's stock `PreventRequestForgery` on
all three panels' own middleware stacks; the OAuth callback route
(GET-only) is structurally exempt from CSRF verification (VerifyCsrfToken
only checks POST/PUT/PATCH/DELETE).
**Residual risk:** Low.

### 5. Host-header poisoning
**Control:** `TrustHosts` locked to the six canonical hostnames
(`CanonicalUrlService::trustedHosts()`), `subdomains: false`. Reset
links (Firm `URL::signedRoute()`, Client Portal `route()`) resolve via
named, panel-domain-bound routes — never `request()->getHost()`.
OAuth `redirect_uri` is generated via `route('integrations.oauth.callback',
[], true)`, now bound to `app.firmsvault.com` by the route's own
`Route::domain()` registration rather than the ambient request Host.
**Residual risk:** **Medium**, explicitly flagged: `routes/webhooks.php`'s
inbound route carries no `domain()` constraint of its own (by design —
confirmed genuinely host-agnostic, no code depends on Host/URL in the
signature-verification path) but the new global `TrustHosts` gate still
applies to it at the request layer. The real hostname Google/Microsoft/
Plaid are currently configured to POST to is not present in this repo
(`INTEGRATIONS_PLAID_WEBHOOK_URL`'s real deployed value lives only in
the actual environment's secrets) — **this must be confirmed and
included in the trusted-hosts list before `TrustHosts` is enabled
anywhere real webhook traffic flows**, or genuine provider webhooks
will be rejected at the request layer even though `routes/webhooks.php`
itself would have handled them correctly. Documented as an explicit
pre-cutover verification step, not silently solved by guesswork.

### 6. Open redirect
**Control:** The only redirects this mission introduces (legacy `/firm`,
`/portal`, `/admin`, `/integrations/oauth/*` GET paths) build their
destination exclusively from `CanonicalUrlService` plus the request's
own path suffix — never from a caller-supplied `redirect`/`return_to`
parameter (confirmed none exists anywhere in this codebase).
**Residual risk:** Low.

### 7. OAuth state attack / PKCE bypass
**Control:** Unchanged, real, and already robust —
`IntegrationOAuthStateService` stores only a SHA-256 hash of a 256-bit
CSPRNG state token server-side (never the session), atomic one-time
consumption via a race-safe `UPDATE ... RETURNING`, 10–30 minute TTL.
PKCE (`PkceService`) is S256-only, envelope-encrypted at rest. Because
neither depends on the session, moving OAuth initiate/callback to
`app.firmsvault.com` does not weaken this in any way — confirmed by
this mission's own audit as the reason the hostname migration is
low-risk for OAuth specifically.
**Residual risk:** Low.

### 8. Integration token disclosure
**Control:** Unchanged — `IntegrationCredentialService` encrypts every
credential via `EmailBodyEncryptionService` (tenant-keyed), decrypts
only through an audited `decryptForOperation()` requiring an active
tenant context and a logged reason.
**Residual risk:** Low, out of this mission's scope to modify.

### 9. Webhook forgery
**Control:** Unchanged — HMAC-SHA256 (`v1=` scheme) with a ±300s replay
window and dual active/rotated secret candidates for the generic
provider path; real providers (Microsoft, Google, Plaid) implement
their own native verification. Neither depends on Host/URL.
**Residual risk:** Low (see threat #5 for the orthogonal
TrustHosts-at-the-request-layer concern).

### 10. IDOR / predictable resource enumeration
**Control:** Pre-existing, unchanged — 147 models use `HasPublicUuid`
(opaque UUIDv7 route keys), confirmed applied selectively (audit/log
tables correctly excluded).
**Residual risk:** Low for resources already covered.

### 11. Cross-Firm / cross-Client access
**Control:** Unchanged — FORCE RLS (168/168 prepared tables, 0 missing,
computed dynamically from `database/migrations/*_force_rls_on_*_table.php`
— no hardcoded count for this mission to accidentally leave stale) +
`TenantContextService`.
**Residual risk:** Low, out of this mission's scope to modify.

### 12. Privilege escalation across guards
**Control:** Structural — a session authenticated on one guard has no
representation at all on another guard; hostname routing is
defense-in-depth only, never the authorization boundary itself.
**Residual risk:** Low.

### 13. Admin compromise
**Control:** `admin.firmsvault.com` is its own domain, own host-only
cookie. **Real, working TOTP MFA is preserved exactly as-is**:
`AuditedAppAuthentication` (audited enrollment/challenge/recovery),
`EnsurePlatformAdminMfaIsEnrolledAndVerified` (5-step enforcement:
fresh re-read, `is_active` fail-closed, enrollment redirect, remember-me
force-logout, reset-stamp invalidation), `PlatformAdminLogin` (no
remember-me checkbox at all for this panel).
**Residual risk:** **Medium** — TOTP remains phishable via real-time
relay/OTP interception, unlike WebAuthn. Explicitly NOT rebuilt or
removed by this mission; upgrade path documented for Mission 1B.

### 14. MFA bypass
**Control:** Unchanged and already defense-in-depth: 5 independent
enforcement steps in `EnsurePlatformAdminMfaIsEnrolledAndVerified`,
proven by the pre-existing `EnsurePlatformAdminMfaIsEnrolledAndVerifiedTest`
suite (9 tests, all still passing after this mission's domain-binding
change).
**Residual risk:** Low.

### 15. Accidental MyAttorney exposure
**Control:** `myattorney.firmsvault.com` is explicitly domain-scoped,
answers only a plain-text placeholder, receives zero Firm/Client/Admin
cookies (host-only cookie scoping), and is `noindex`.
**Residual risk:** Low.

### 16. Stale legacy routes
**Control:** The pre-existing `/firm`, `/portal`, `/admin`, and
`/integrations/oauth/*` paths on the marketing host now only exist as
GET-only redirects — the real panels/routes no longer answer there at
all. POST/PUT/PATCH/DELETE to any legacy path is never redirected.
**Residual risk:** Low.

## Summary

Of the 16 threats reviewed, 14 have a control implemented/preserved and
either tested or structurally unaffected by this mission. Two carry an
explicit, non-silent residual risk: webhook-host trust (#5, requires a
real pre-cutover verification step this repo cannot resolve on its own)
and TOTP-vs-phishing-resistance (#13, explicitly deferred to Mission 1B
per this mission's own scope boundary, not silently accepted as solved).
