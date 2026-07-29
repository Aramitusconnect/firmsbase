<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassification;
use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Enums\ProviderKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ProviderBillingClassifierTest — checkpoint4-design-cost-control.md §2
 * step 5. `ProviderBillingClassifier::classify()` is a pure, static
 * lookup table (no I/O) — proves the closed
 * product -> {endpointCategory, isOptional, requiresExplicitConfirmation,
 * isCacheable} mapping the implementation actually ships, including the
 * two judgment calls its own docblock discloses: `item`/`transactions`
 * are the only two never-optional products, and `('balance','get')` is
 * the only classification that requires explicit confirmation and is
 * never cacheable.
 */
class ProviderBillingClassifierTest extends TestCase
{
    use RefreshDatabase;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ProviderBillingClassifier;
    }

    public function test_classify_returns_a_billing_classification_value_object(): void
    {
        $result = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $this->assertInstanceOf(ProviderBillingClassification::class, $result);
        $this->assertSame('transactions', $result->product);
        $this->assertSame('sync', $result->billingOperation);
    }

    public function test_capability_string_is_product_colon_billing_operation(): void
    {
        $result = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');

        $this->assertSame('balance:get', $result->capability());
    }

    public function test_item_and_transactions_are_the_only_never_optional_products(): void
    {
        $item = $this->classifier->classify(ProviderKey::Plaid, 'item', 'get');
        $transactions = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $this->assertFalse($item->isOptional);
        $this->assertFalse($transactions->isOptional);
    }

    #[DataProvider('optionalProductProvider')]
    public function test_every_other_product_defaults_to_optional(string $product): void
    {
        $result = $this->classifier->classify(ProviderKey::Plaid, $product, 'get');

        $this->assertTrue($result->isOptional, "Expected product [{$product}] to be optional.");
    }

    public static function optionalProductProvider(): array
    {
        return [
            ['balance'], ['auth'], ['identity'], ['identity_match'], ['liabilities'],
            ['investments'], ['income'], ['statements'], ['enrich'],
            ['identity_verification'], ['monitor'],
        ];
    }

    public function test_only_balance_get_requires_explicit_confirmation(): void
    {
        $balanceGet = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $balanceOther = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'refresh');
        $transactionsSync = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $this->assertTrue($balanceGet->requiresExplicitConfirmation);
        $this->assertFalse($balanceOther->requiresExplicitConfirmation);
        $this->assertFalse($transactionsSync->requiresExplicitConfirmation);
    }

    public function test_balance_is_never_cacheable_but_every_other_product_is(): void
    {
        $balance = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $transactions = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');
        $statements = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->assertFalse($balance->isCacheable);
        $this->assertTrue($transactions->isCacheable);
        $this->assertTrue($statements->isCacheable);
    }

    #[DataProvider('endpointCategoryProvider')]
    public function test_endpoint_category_mapping(string $product, string $expectedCategory): void
    {
        $result = $this->classifier->classify(ProviderKey::Plaid, $product, 'get');

        $this->assertSame($expectedCategory, $result->endpointCategory);
    }

    public static function endpointCategoryProvider(): array
    {
        return [
            ['item', 'core_banking_data'],
            ['transactions', 'core_banking_data'],
            ['balance', 'core_banking_data'],
            ['auth', 'core_banking_data'],
            ['liabilities', 'core_banking_data'],
            ['identity', 'identity'],
            ['identity_match', 'identity'],
            ['identity_verification', 'identity'],
            ['investments', 'investments'],
            ['income', 'income'],
            ['statements', 'statements'],
            ['enrich', 'enrich'],
            ['monitor', 'kyc_aml'],
        ];
    }

    public function test_an_unrecognized_product_falls_back_to_core_banking_data_rather_than_throwing(): void
    {
        $result = $this->classifier->classify(ProviderKey::Plaid, 'some_future_product', 'get');

        $this->assertSame('core_banking_data', $result->endpointCategory);
    }
}
