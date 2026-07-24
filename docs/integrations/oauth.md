# OAuth Connect/Callback/Refresh Flow

## 1. Routes

`routes/web.php` (Checkpoint 5), gated by the standard `auth` middleware:

```php
Route::middleware(['auth'])->prefix('integrations/oauth')->name('integrations.oauth.')->group(function () {
    Route::get('{firmIntegration}/initiate', [OAuthConnectionController::class, 'initiate'])->name('initiate');
    Route::get('callback', [OAuthConnectionController::class, 'callback'])->name('callback');
});
```

`App\Http\Controllers\Integrations\OAuthConnectionController` is thin — actual orchestration lives in `App\Integrations\Services\ProviderConnectionService`.

## 2. Initiate

`ProviderConnectionService::initiateOAuthConnection(FirmIntegration $connection, int $currentUserId, string $redirectUri)`:

1. Validates `redirectUri` via `ProviderRedirectUrlValidator` (SSRF-safe check — see [security-model.md](security-model.md)).
2. Calls `IntegrationOAuthStateService::initiate()`, which:
   - Generates a CSPRNG `state` token and a PKCE verifier (`PkceService`, S256 only).
   - Envelope-encrypts the PKCE verifier before persisting it to `integration_oauth_states.verifier_ciphertext`.
   - Persists the row with `initiating_user_id` set to the acting user (this is what the RLS self-lookup carve-out on `integration_oauth_states` keys against — see [rls-and-tenancy.md](rls-and-tenancy.md)).
3. Records `integration_oauth.authorization_initiated` via `TimelineEventRecorder`.
4. Returns an `OAuthInitiationResult` (the provider's authorization URL) for the controller to redirect to.

## 3. Callback

`IntegrationOAuthStateService::resolveAndConsume(string $rawState, int $currentUserId)`:

- Looks up the pending state row **before any firm context exists** — this is the one place in the framework a query legitimately runs against a FORCE-RLS table (`integration_oauth_states`) using only caller-identity context, not firm context. Enabled by the self-lookup RLS carve-out.
- Additionally filters on `opaque_token_hash` in application code (never relies on RLS as the sole predicate) — required so a user with more than one connect/reauthorize flow in flight (multiple tabs/providers) cannot have an arbitrary one of their own pending states returned instead of the specific one their callback request is actually continuing.
- Enforces single-use consumption — a second attempt to consume the same state throws `OAuthStateAlreadyConsumedException`.
- Enforces expiry — `OAuthStateExpiredException`.
- A state value that doesn't resolve to any row throws `OAuthStateNotFoundException`.

`ProviderConnectionService::completeOAuthCallback()` then:

- Re-validates `redirect_uri` fresh (never trusts a cached "it was safe when I checked it earlier" boolean) — throws `OAuthRedirectUriMismatchException` on mismatch.
- Re-resolves the acting `FirmUser` fresh (never reused from initiate time) and re-confirms membership — an OAuth flow spanning a role change or membership change between initiate and callback is caught here, not silently honored with the initiate-time role. Throws `OAuthAccountMismatchException` if the acting FirmUser's membership no longer matches the FirmUser that initiated the flow.
- Persists the resulting credential via `IntegrationCredentialService` (never handled inline).

## 4. Token refresh

`ProviderConnectionService::refreshConnectionToken(FirmIntegration $connection): OAuthCallbackResult` — synchronous refresh logic, invoked either directly or via the queued job:

- `App\Integrations\Jobs\RefreshIntegrationToken` (`ShouldQueue`) — the production dispatch path (Checkpoint 8). Its constructor carries only bare, non-secret integer FKs (`firmIntegrationId`, `firmId`) — never a token, never a credential ID, never a hydrated model. `firmId` is included deliberately: `firm_integrations` is FORCE-RLS'd, so a fresh worker process with zero tenant context cannot safely read it first to discover which firm owns a given `firmIntegrationId`.
- `IntegrationCredentialService::withRefreshLock()` guards concurrent refresh attempts for the same connection.
- Exhaustion path: `ProviderConnectionService::markRefreshExhausted(FirmIntegration $connection, string $category)` transitions the connection when refresh attempts are exhausted — see [known-limitations.md](known-limitations.md) (KR-02, the sync-item retry-exhaustion fix, is a related but distinct fix; refresh-exhaustion handling is a separate code path).

## 5. Disconnect

`ProviderConnectionService::disconnect(FirmIntegration $connection, int $currentUserId)` — revokes the active credential via `IntegrationCredentialService::revoke()` and transitions connection status. This is the only supported way to invalidate a connection's OAuth grant from within the application; there is no separate "revoke token at provider" call for the TestProvider (it makes no real network calls) and no real provider exists to test that behavior against. See [runbooks/oauth-callback-failure.md](runbooks/oauth-callback-failure.md), [runbooks/oauth-refresh-invalid-grant.md](runbooks/oauth-refresh-invalid-grant.md), and [runbooks/oauth-refresh-transient-failure.md](runbooks/oauth-refresh-transient-failure.md) for operator-facing failure handling.

## 6. Exceptions (closed vocabulary)

`app/Integrations/Exceptions/`: `OAuthStateNotFoundException`, `OAuthStateExpiredException`, `OAuthStateAlreadyConsumedException`, `OAuthRedirectUriMismatchException`, `OAuthAccountMismatchException`, plus the PKCE-specific `InvalidPkceVerifierException` and the authorization-code-specific `AuthorizationCodeAlreadyUsedException`/`ExpiredAuthorizationCodeException` (exercised by TestProvider's simulated OAuth — see [testprovider.md](testprovider.md)).
