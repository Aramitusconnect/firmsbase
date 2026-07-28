<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Models\FirmIntegration;

/**
 * SupportsBalanceContract — implemented by providers that expose an
 * on-demand, real-time account-balance retrieval capability that is
 * deliberately NOT part of the generic pull/sync surface
 * (`SupportsPullSyncContract`). checkpoint4-design-plaid-provider-core.md
 * §6.6 names `fetchBalance()` as Plaid's dedicated, on-demand-only
 * entry point for exactly this reason ("real-time, tightly
 * rate-limited, billed-per-call... exposed instead as a dedicated,
 * on-demand-only `fetchBalance()` method, the entry point the
 * cost-control track's Live Balance safeguards wrap around"), and
 * checkpoint4-design-cost-control.md §2 step 4 names `SupportsBalanceContract`
 * explicitly, alongside `SupportsLiabilitiesContract`, as an example of
 * the generic `Supports<Product>Contract` family the pipeline's
 * capability-check step asserts against.
 *
 * This interface is deliberately provider-agnostic — it lives in
 * `App\Integrations\Contracts\`, never under a provider-specific
 * namespace, and this checkpoint's cost-control track (which defines
 * it) has no compile-time dependency on `PlaidProvider` actually
 * implementing it yet. `App\Integrations\Billing\ProviderLiveBalanceConfirmationService`
 * resolves the connection's registered provider generically via
 * `App\Integrations\Core\ProviderRegistry` and asserts `instanceof
 * SupportsBalanceContract` before calling `fetchBalance()` — the same
 * `instanceof Supports*Contract` composition pattern every other
 * capability check in this codebase already uses, never a branch on
 * provider identity.
 *
 * Mirrors `SupportsPullSyncContract::pull()`'s own `array $context`
 * shape: the provider adapter resolves and decrypts its own access
 * credential internally (via `IntegrationCredentialService::decryptForOperation()`,
 * exactly as every other capability method already does), so this
 * contract never carries a plaintext credential across a method
 * boundary.
 */
interface SupportsBalanceContract
{
    /**
     * Fetch the current, real-time balance for one account under the
     * given connection. Never cached by the provider adapter itself —
     * Plaid's own documentation describes Balance as real-time-only,
     * and any caching/cooldown/dedup discipline belongs one layer up,
     * in `App\Integrations\Billing\ProviderLiveBalanceConfirmationService`
     * and the pipeline it drives, never inside this method.
     *
     * @param  array<string, mixed>  $context  caller-supplied
     *                                         connection/auth context,
     *                                         matching every other
     *                                         `Supports*Contract`
     *                                         method's shape.
     * @return array<string, mixed> provider-shaped balance payload
     *                              (e.g. available/current amounts,
     *                              currency, as-of timestamp) — the
     *                              caller is responsible for mapping
     *                              this into whatever closed, sanitized
     *                              shape it persists or displays.
     */
    public function fetchBalance(FirmIntegration $connection, string $accountId, array $context): array;
}
