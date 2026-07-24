# Security Model

## 1. Credential handling

`App\Integrations\Services\IntegrationCredentialService` is the **sole writer** of `integration_credentials` rows. It reuses `App\Services\EmailBodyEncryptionService` for encryption — the same chain `AiProviderKeyService`/`WebhookSecretService` already use elsewhere in this codebase — deliberately not a second, integration-specific encryption system.

- `store()` / `replace()` / `rotate()` / `revoke()` / `decryptForOperation()` / `reEncrypt()` / `findActiveCredential()` / `getMaskedMetadata()` / `withRefreshLock()` are the only public entry points (`app/Integrations/Services/IntegrationCredentialService.php`).
- `masked_display_metadata` may only ever be populated from caller-supplied, genuinely non-secret fields (a provider display label, granted scopes, expiry) — never derived from, or computed as a substring/hash/truncation of, the plaintext secret. `getMaskedMetadata()` performs no decrypt call at all.
- Every public method fails closed: verification failures throw `RuntimeException`/`InvalidArgumentException` with a non-secret-containing message, and nothing is persisted when a check fails.
- **Global rule for every runbook and every support action in this tree: credentials are never decrypted "to check" them.** There is no ad hoc "view secret" surface anywhere in this framework, and none may be built without a separate, explicitly authorized checkpoint.
- **Disclosed, tracked gap**: `reEncrypt()`'s ordering relative to key rotation carries a known caveat documented in the method's own docblock (`app/Integrations/Services/IntegrationCredentialService.php:300`). This must not be worked around ad hoc by any operator action — see [known-limitations.md](known-limitations.md).
- There is no standalone credential-rotation operator tool. Rotation happens only via `rotate()` (same connection, new credential material) or reconnect/disconnect at the connection level — see [runbooks/suspected-secret-exposure.md](runbooks/suspected-secret-exposure.md).

## 2. OAuth security

- **PKCE, S256 only** — `App\Integrations\Support\PkceService` never generates or accepts the `plain` challenge method (RFC 7636). The verifier is envelope-encrypted before being persisted to `integration_oauth_states.verifier_ciphertext`; `PkceService` itself never touches storage.
- **CSPRNG-generated state tokens** — both the PKCE verifier and the OAuth `state` value use `random_bytes()`, never `Str::random()`'s default path.
- **Redirect URI validation** — `App\Integrations\Support\ProviderRedirectUrlValidator` is modeled on the existing `WebhookDestinationValidationService`'s SSRF-safe literal-IP-range checks, but is deliberately re-validated at **both** OAuth-initiate time and callback-claim time, never trusted from a cached decision. In this framework, `redirect_uri` is never a caller/request-suppliable value — it is always computed fresh from this application's own fixed OAuth callback route — narrowing the practical SSRF surface further than the general-purpose validator it's modeled on.
- **State replay protection** — `integration_oauth_states` rows are single-use; `IntegrationOAuthStateService::resolveAndConsume()` enforces the row's `opaque_token_hash` at the query layer (never relying on RLS as the sole predicate) so a user with multiple in-flight connect flows cannot have an arbitrary pending state substituted for the one their callback is actually continuing. See [oauth.md](oauth.md).

## 3. Webhook security

See [webhooks.md](webhooks.md) for the full inbound flow. Headline controls: exact raw-body HMAC-SHA256 signature verification (`InboundWebhookSignatureVerifier`), bounded 2-candidate secret rotation, replay protection, database-backed idempotency at both the pre-tenant receipt layer and the tenant-owned event layer, and IP-keyed throttling plus a 256 KB payload-size gate ahead of any database write (`routes/webhooks.php`).

## 4. Authorization tiers

Two independent gates apply to every firm-plane integration action, in this frozen order (entitlement before role):

1. **Entitlement** — `IntegrationEntitlementPolicyService` checks the `integration` `module_catalog` code via the existing `EntitlementService`/`firm_entitlements` mechanism. No new entitlement system was introduced.
2. **Role** — `App\Integrations\Policies\FirmIntegrationPolicy` (the first standard Laravel `Policy` class in this codebase) delegates to `IntegrationAccessPolicyService` (non-financial tier: connect/configure/disconnect ceilinged at FirmOwner/Attorney; view ceilinged at FirmOwner/Attorney/Paralegal/LegalAssistant; usage/billing view at FirmOwner/BillingStaff) or `FinancialIntegrationAccessPolicyService` (financial tier, deliberately kept separate, unused today — no financial provider is registered anywhere in this mission).

Role ceilings may only be **narrowed** by entitlement, never widened — `Receptionist` never appears in any allowlist in either tier's source.

Every policy method also independently re-confirms the resolved `FirmUser`'s `firm_id` matches the target row's `firm_id` before authorizing anything instance-scoped. This is a defense-in-depth application-layer check, **not** a substitute for the FORCE RLS policy on the underlying table, which remains the actual tenant-isolation boundary regardless of what the policy layer decides. See [rls-and-tenancy.md](rls-and-tenancy.md).

## 5. Platform-plane (SuperAdmin) access

`App\Services\PlatformFirmIntegrationBoundedAccessService` is the single chokepoint every platform-oversight Filament page/action must go through:

1. `PlatformStaffAccessPolicyService::canAccessIntegrationOversight()` — the coarse, role-level gate, checked first.
2. For roles that pass (1) but are not unconditionally-trusted ceiling roles (SuperAdmin, PlatformAdmin, ImplementationSpecialist) — i.e. SupportAgent — every per-firm read or action additionally requires an active, governed `SupportAccessSession` scoped to the exact target firm.

The aggregate/sanitized platform overview page needs neither check — it never calls into this class. See [operations-superadmin.md](operations-superadmin.md).

## 6. Provider-specific leakage invariant

No core framework code branches on `ProviderKey::Test` or a hardcoded `'test'` string outside sanctioned layers (the provider's own class and the enum definition itself) — verified by a repo-wide sweep at HEAD. Provider-specific behavior stays inside the provider's own class, never leaking into shared framework code paths.

## 7. TestProvider isolation

See [testprovider.md](testprovider.md) for the full detail, including the environment-name guard being shipped in Checkpoint 14.

## 8. Rate limiting

`App\Integrations\Support\PerConnectionRateLimiter` exists, is namespaced per `firm_integration_id` (never globally, never per-firm-alone), and is a proactive cache-based gate additive to the outbox's own reactive `next_attempt_at` eligibility predicate. **It is not wired into any production call site today** — confirmed via repo-wide search; the only place a "rate-limited" signal appears in production behavior is the reactive TestProvider simulation. This must be decided (wired or explicitly deferred) before any real provider is onboarded. See [known-limitations.md](known-limitations.md) (KR register) and [runbooks/rate-limiting.md](runbooks/rate-limiting.md).

## 9. What this document does not cover

Deployment-time infrastructure security (network isolation, IAM, secrets provisioning) is covered by the pre-existing `docs/ecs/` and `docs/security/` documentation sets for the shared ECS infrastructure, not duplicated here. This framework has never been deployed — see [README.md](README.md#deployment-authorization).
