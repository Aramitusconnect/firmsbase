# Mission 1 — Domain & Security Boundary Architecture: Threat Model

Section 48 deliverable. Covers the hostname/session/routing architecture
introduced by this mission. Full extreme-hardening controls (WebAuthn,
IAM least-privilege, CloudTrail/GuardDuty, CSP, secret rotation, etc.)
are Mission 1B's scope, not this document's — noted per-threat below
where 1B strengthens something this mission only partially closes.

## Assets

- Firm-user sessions (`web` guard, `app.firmsvault.com`)
- Client sessions (`client` guard, `client.firmsvault.com`)
- SuperAdmin/platform-admin sessions (`platform_admin` guard, `admin.firmsvault.com`)
- Future MyAttorney/public prospect sessions (not built — reserved hostname only)
- OAuth tokens for firm-operated integrations (schema exists; no live provider yet — see final report item 28)
- Sensitive Documents, Client/Matter records, Trust and Accounting data (protected by FORCE RLS, unchanged by this mission)
- Firm/Client/PlatformAdmin credentials (password hashes, remember tokens, password-reset tokens)
- Signed/temporary URLs (none exist yet in this codebase — reserved architecture only)

## Trust boundaries

```
Internet → firmsvault.com (marketing, public/indexable)
Internet → app.firmsvault.com (Firm, authenticated)
Internet → client.firmsvault.com (Client Portal, authenticated)
Internet → admin.firmsvault.com (SuperAdmin, authenticated, highest-security zone)
Internet → myattorney.firmsvault.com (reserved placeholder, public/untrusted by design)
Internet → api.firmsvault.com (reserved, currently answers nothing)
ALB → ECS (container, always plain HTTP internally — TLS terminates at the ALB)
ECS → PostgreSQL (RLS-enforced tenant boundary, unchanged by this mission)
ECS → AWS services (Secrets Manager, S3 — unchanged by this mission)
ECS → third-party APIs (none live yet — every integration in this codebase is a simulated/no-op foundation, per this mission's own Phase A audit)
```

## Threats

### 1. Cross-subdomain cookie leakage
**Control:** Every panel's session cookie is host-only (`session.domain`
forced to `null` regardless of environment config —
`ConfigurePanelSessionCookie`), so no cookie is ever sent to a different
canonical host by the browser itself. `__Host-` prefix applied whenever
`session.secure` is true (staging/production).
**Detective:** None beyond normal session/audit logging (unchanged).
**Tests:** `SessionCookieIsolationTest` (cookie name + no-Domain-attribute
per panel).
**Residual risk:** Low. If a future environment ever set a broad
`SESSION_DOMAIN=.firmsvault.com`, this middleware still overrides it to
`null` — the only bypass would be a code change removing that override.
**1B:** No further hardening planned; this is considered closed.

### 2. Session confusion (one guard's session usable on another guard/panel)
**Control:** Distinct guards (`web`/`client`/`platform_admin`), distinct
identity tables, distinct panel `->authGuard()` bindings; Filament's own
`Authenticate` middleware calls `Auth::shouldUse()` per panel.
**Tests:** `SessionCookieIsolationTest::test_a_firm_authenticated_session_has_no_standing_access_to_the_client_portal` and its Client/Admin counterparts; pre-existing `CrossPanelAuthGuardTest`.
**Residual risk:** Low.

### 3. Session fixation
**Control:** Unchanged from before this mission — Filament's stock
`AuthenticateSession` middleware (session regeneration on login) applies
to all three panels identically.
**Tests:** Not newly tested by this mission (pre-existing Filament
behavior, out of this mission's own audit scope).
**Residual risk:** Low, inherited from Filament's framework defaults.

### 4. Authentication bypass via hostname confusion
**Control:** Hostname alone never implies identity — every panel still
requires `Filament\Http\Middleware\Authenticate` +
`FilamentUser::canAccessPanel()`; hostname routing is defense-in-depth
only (section 29 of the mission brief).
**Tests:** `CanonicalHostRoutingTest` (each host serves only its own
panel's login page for a guest); pre-existing `FirmUserLoginPanelAccessTest`/`PlatformAdminLoginPanelAccessTest`.
**Residual risk:** Low.

### 5. Host-header poisoning
**Control:** `TrustHosts` middleware (bootstrap/app.php), locked to the
six canonical hostnames from `CanonicalUrlService::trustedHosts()`,
`subdomains: false`. A non-matching Host throws
`SuspiciousOperationException`, rendered as a plain 400 with no
diagnostic detail. Password-reset/verification links are built from
`CanonicalUrlService`/Filament's own domain-bound named routes, never
from `request()->getHost()`.
**Detective:** None beyond the 400 response itself; no dedicated
alerting (1B scope — "suspicious-login alerts" etc.).
**Tests:** `CanonicalHostRoutingTest::test_an_unrecognized_host_is_rejected_safely`;
`PasswordResetCanonicalHostTest` (host-poisoning attempt on the reset
request).
**Residual risk:** Medium in local/testing environments only —
`TrustHosts` is a Laravel-framework no-op under `runningUnitTests()`/
`environment('local')` by design; the routing-layer defense (no
domain-scoped route matches an unrecognized host, so it 404s regardless)
remains active even there.
**1B:** Consider WAF-level host-header filtering as an additional
network-layer control.

### 6. IDOR / predictable resource enumeration
**Control:** Pre-existing, unchanged by this mission — ~130 models
already use `HasPublicUuid` (opaque UUID route keys), confirmed by this
mission's own audit. `Task`/`TaskDependency` remain intentionally
raw-`id`-keyed (documented as internal/staff-facing only, never routed
directly).
**Tests:** Pre-existing RLS/tenant-isolation suites (unchanged, still
passing).
**Residual risk:** Low for resources already covered; unchanged/
unaudited for any resource built after this mission that forgets to
apply `HasPublicUuid`.

### 7. Cross-Firm / cross-Client access
**Control:** Unchanged — FORCE RLS + `TenantContextService`, verified by
the pre-existing RLS test suite (thousands of tests, all still passing
after this mission's changes).
**Tests:** Full pre-existing RLS suite, re-run as part of this mission's
own final gate.
**Residual risk:** Low, out of this mission's own scope to modify.

### 8. CSRF
**Control:** Filament's stock `PreventRequestForgery` middleware, on
every panel's own middleware stack, unmodified.
**Tests:** `SessionCookieIsolationTest::test_a_forged_post_without_a_csrf_token_is_rejected_on_every_panel`.
**Residual risk:** Low.

### 9. Open redirect
**Control:** The only redirects this mission introduces (legacy `/firm`
and `/admin` GET paths) build their destination exclusively from
`CanonicalUrlService` (server-controlled) plus the request's own path
suffix — never from a caller-supplied `redirect`/`return_to` parameter.
**Tests:** `CanonicalHostRoutingTest::test_legacy_redirect_ignores_any_attempted_redirect_override_parameter`.
**Residual risk:** Low.

### 10. Signed-link leakage
**Control:** N/A — no signed URLs exist anywhere in this codebase
(confirmed by this mission's own audit). Documented constraint for
whoever builds the first one: Laravel 13's `URL::signedRoute()` signs
the full absolute URL including host by default, so generation and
verification must use the same canonical host or explicitly pass
`$absolute = false`.
**Tests:** None (nothing to test yet).
**Residual risk:** N/A today; a real risk the first time a signed URL
is actually built — flagged for that future work, not for this mission.

### 11. OAuth state attacks
**Control:** N/A — no live OAuth flow exists anywhere in this codebase
(every integration is schema/simulation only, confirmed by audit).
**Tests:** None.
**Residual risk:** N/A today.

### 12. Privilege escalation (Firm user → Admin, Client → Firm user, etc.)
**Control:** Separate identity tables, separate guards, no shared
session/cookie — structurally, not just by convention.
**Tests:** `SessionCookieIsolationTest` (Client/Firm/Admin cross-guard
tests), pre-existing `CrossPanelAuthGuardTest`.
**Residual risk:** Low.

### 13. Admin compromise
**Control:** SuperAdmin panel (`admin.firmsvault.com`) is its own
domain, own host-only cookie, zero standing tenant-context middleware
(confirmed unchanged from before this mission). MFA enforcement remains
absent — explicitly out of this mission's scope.
**Tests:** `CanonicalHostRoutingTest`, `SecurityHeadersAndSeoTest`
(admin-host-specific assertions).
**Residual risk:** **High** until Mission 1B. Reported explicitly in the
final report as `SUPERADMIN_PHISHING_RESISTANT_AUTH = MISSION_1B_REQUIRED`
per section 26 — this mission does not fake MFA/step-up auth it has not
built.
**1B:** WebAuthn/passkeys, mandatory phishing-resistant MFA, step-up
auth, session anomaly detection, IAM least-privilege for any admin-role
AWS access.

### 14. Accidental MyAttorney exposure
**Control:** `myattorney.firmsvault.com` is explicitly domain-scoped,
answers only a plain-text placeholder, receives zero Firm/Client/Admin
cookies (host-only cookie scoping means the browser itself never sends
them there), and is `noindex`.
**Tests:** `CanonicalHostRoutingTest::test_myattorney_host_never_exposes_the_firm_panel`,
`SecurityHeadersAndSeoTest::test_myattorney_placeholder_is_noindex_and_distinctly_worded`.
**Residual risk:** Low.

### 15. Stale legacy routes
**Control:** The pre-existing `/firm` and `/admin` paths on the
marketing host now only exist as GET-only redirects to the canonical
hosts — the actual panels no longer answer there at all. A
POST/PUT/PATCH/DELETE to either legacy path is never redirected (falls
through to Laravel's normal method-not-allowed/not-found handling).
**Tests:** `CanonicalHostRoutingTest` (GET redirect + POST-never-redirected
assertions).
**Residual risk:** Low.

## Summary

Of the 15 threats reviewed, 13 have a control implemented and tested by
this mission (or were already closed and remain unaffected). Two are
explicitly deferred with reasoning: signed-URL host-binding (#10, no
signed URL exists yet — nothing to secure prematurely) and OAuth state
handling (#11, no live OAuth flow exists yet). One residual **high**
risk is explicitly flagged rather than silently accepted: SuperAdmin
lacks phishing-resistant MFA, which is Mission 1B's own stated scope,
not something this mission claims to have solved.
