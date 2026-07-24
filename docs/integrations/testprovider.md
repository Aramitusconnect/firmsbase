# TestProvider

`App\Integrations\Providers\TestProvider\TestProvider` (`app/Integrations/Providers/TestProvider/TestProvider.php`) is the **only** concrete provider implementation anywhere in this mission. It exists so every framework capability can be exercised end to end — registration, capability discovery, simulated OAuth, API-key validation, webhook verification, push/pull sync, incremental cursors, health checks, disconnect — before any real provider is ever built.

## 1. Hard restrictions

- Makes **zero** real HTTP/network calls in any method — every method returns purely synthetic, in-memory data.
- Every secret/credential/token-shaped string it returns or accepts is synthetic; no real credential is ever involved.
- Implements 9 contracts: `IntegrationProviderContract`, `SupportsOAuthContract`, `SupportsApiKeyContract`, `SupportsWebhooksContract`, `SupportsHealthCheckContract`, `SupportsPullSyncContract`, `SupportsPushSyncContract`, `SupportsIncrementalSyncContract`, `SupportsDisconnectContract` — it is the framework's full-capability reference implementation.

## 2. Registration and environment gating

Two independent gates, both checked against `INTEGRATIONS_TEST_PROVIDER_ENABLED` (default `false`):

1. **`config/integrations.php`** — the provider-registry entry (`ProviderKey::Test->value => ...`). When the gate fails, the value is `null` and `ProviderRegistry::get()` throws `UnknownProviderException` — TestProvider is **absent from the registry entirely**, not merely "registered but disabled."
2. **`TestProvider::isEnabledByEnvironment()`** — an independent re-check of the same underlying condition, deliberately not relying solely on the registry map having filtered the class out correctly elsewhere.

### Checkpoint 14 correction: the environment-name guard

Both gates above previously read only the single `INTEGRATIONS_TEST_PROVIDER_ENABLED` env var — a single mis-set value in a production environment would have had no independent backstop. **Checkpoint 14 adds a second, orthogonal, independently-failing condition to both gates: the application must NOT be running in the `production` environment (`! app()->environment('production')`), regardless of the flag's value.**

- `config/integrations.php`'s provider-registration entry: `env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false) && ! app()->environment('production')`.
- `TestProvider::isEnabledByEnvironment()`: `filter_var(env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false), FILTER_VALIDATE_BOOLEAN) && ! app()->environment('production')`.

This is a **pure AND-narrowing change** — every case that was previously disabled remains disabled; the only behavior removed is "flag true in an environment named `production`," which must never have been enabled in the first place. It closes a fail-closed gap in an already-shipped, already-intended control; it does not add new capability. This is the **one** flag-adjacent production change shipped in Checkpoint 14 — see [feature-flag-rollout.md](feature-flag-rollout.md) for how this differs from the 3 proposed capability flags that were **not** shipped.

This change is implemented and reviewed under the standard narrow-production-change discipline (dedicated diff review, regression test proving inertness in a simulated production environment regardless of flag value, negative-control test proving availability persists in non-production environments when the flag is true, full pre/post-commit suite runs, independent security sign-off) as a change separate from this documentation tree — this document only describes the resulting behavior, it does not implement it.

## 3. Simulated failure sentinels

`TestProvider::exchangeCodeForToken()` / `refreshToken()` recognize a magic, non-secret sentinel value that, when passed as the code/refresh token, simulates a raw outbound-call failure (`SimulatedProviderFailureException`) instead of a normal success/error response — this is how the test suite exercises transient-failure handling without any real network dependency.

## 4. Unwired method

`TestProvider::rotateWebhookSigningKey()` exists and is fully implemented but has no production call site — it is exercised only by the test suite. See [known-limitations.md](known-limitations.md).

## 5. Provider-specific leakage discipline

No core framework code branches on `ProviderKey::Test` or a hardcoded `'test'` string outside this class and the `ProviderKey` enum definition itself — verified by a repo-wide sweep at HEAD. See [security-model.md](security-model.md) §6.
