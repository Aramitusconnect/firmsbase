<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;

/**
 * ProviderBillingClassifier — pure, static lookup table (no I/O),
 * pipeline step 5 (checkpoint4-design-cost-control.md §2 step 5). Maps
 * a `(providerKey, product, billingOperation)` triple onto a
 * `ProviderBillingClassification`.
 *
 * The `product` vocabulary matches `provider_rate_card_entries.product`'s
 * documented governed-string list exactly (§1.1): `balance`,
 * `transactions`, `auth`, `identity`, `identity_match`, `liabilities`,
 * `investments`, `income`, `statements`, `enrich`,
 * `identity_verification`, `monitor`, `item`.
 *
 * Two judgment calls, made explicit here since the source design does
 * not enumerate every product's endpoint-category/optionality directly
 * (it only gives illustrative examples — §4.3's "e.g. all of
 * core_banking_data: Auth+Transactions+Balance+Liabilities" and §4.3's
 * "core Item lifecycle/Transactions sync can never be firm-suspended"):
 *
 *   1. `isOptional` is false ONLY for `item` (Item lifecycle) and
 *      `transactions` — the two the design explicitly names as never
 *      firm-suspendable. Every other product defaults to optional.
 *   2. `endpointCategory` groups products into the seven categories
 *      §2 step 5's own closed vocabulary lists
 *      (`core_banking_data|identity|investments|income|statements|enrich|kyc_aml`),
 *      following §4.3's own worked example for `core_banking_data`
 *      (Auth+Transactions+Balance+Liabilities, extended here to
 *      include `item` since Item-lifecycle calls are the same
 *      foundational banking-connection category) and the natural
 *      1:1 mapping for every other category (`identity`+`identity_match`+
 *      `identity_verification` -> `identity`; `monitor` -> `kyc_aml`,
 *      Plaid's ongoing AML/watchlist-monitoring product family).
 */
final class ProviderBillingClassifier
{
    private const NEVER_OPTIONAL_PRODUCTS = ['item', 'transactions'];

    /**
     * @var array<string, string>
     */
    private const ENDPOINT_CATEGORY_MAP = [
        'item' => 'core_banking_data',
        'transactions' => 'core_banking_data',
        'balance' => 'core_banking_data',
        'auth' => 'core_banking_data',
        'liabilities' => 'core_banking_data',
        'identity' => 'identity',
        'identity_match' => 'identity',
        'identity_verification' => 'identity',
        'investments' => 'investments',
        'income' => 'income',
        'statements' => 'statements',
        'enrich' => 'enrich',
        'monitor' => 'kyc_aml',
    ];

    public function classify(ProviderKey $providerKey, string $product, string $billingOperation): ProviderBillingClassification
    {
        $endpointCategory = self::ENDPOINT_CATEGORY_MAP[$product] ?? 'core_banking_data';
        $isOptional = ! in_array($product, self::NEVER_OPTIONAL_PRODUCTS, true);
        $requiresExplicitConfirmation = $product === 'balance' && $billingOperation === 'get';
        $isCacheable = $product !== 'balance';

        return new ProviderBillingClassification(
            product: $product,
            billingOperation: $billingOperation,
            endpointCategory: $endpointCategory,
            isOptional: $isOptional,
            requiresExplicitConfirmation: $requiresExplicitConfirmation,
            isCacheable: $isCacheable,
        );
    }
}
