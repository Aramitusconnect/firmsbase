<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderRateCardResolver;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ProviderRateCardResolverTest — checkpoint4-design-cost-control.md §2
 * step 6. Proves the three-tier precedence resolution
 * (`platform_default < package_default < firm_override`), byte-for-byte
 * mirroring `EntitlementService::resolve()`'s own sort-by-precedence
 * shape, and the effective-dated window resolution
 * (`provider_rate_card_entries.effective_from`/`effective_to`).
 * `provider_rate_card_entries` is Global/no-RLS (checkpoint4-combined-design.md
 * §1.1), so no tenant context is required to create rows on it directly.
 */
class ProviderRateCardResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProviderRateCardResolver $resolver;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderRateCardResolver;
        $this->classifier = new ProviderBillingClassifier;
    }

    private function rateCardRow(array $overrides = []): ProviderRateCardEntry
    {
        return ProviderRateCardEntry::query()->create(array_merge([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'transactions',
            'billing_operation' => 'sync',
            'environment' => 'production',
            'scope_type' => 'platform_default',
            'scope_id' => null,
            'provider_cost_cents' => 10,
            'customer_price_cents' => 25,
            'currency' => 'usd',
            'unit' => 'request',
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ], $overrides));
    }

    public function test_returns_null_when_no_rate_card_row_exists_at_any_scope(): void
    {
        $firm = Firm::factory()->create();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertNull($result);
    }

    public function test_resolves_a_platform_default_row_when_it_is_the_only_candidate(): void
    {
        $firm = Firm::factory()->create();
        $row = $this->rateCardRow();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertNotNull($result);
        $this->assertSame($row->id, $result->id);
        $this->assertSame('platform_default', $result->scope_type);
    }

    public function test_firm_override_wins_over_platform_default_when_both_exist(): void
    {
        $firm = Firm::factory()->create();
        $this->rateCardRow(); // platform_default
        $override = $this->rateCardRow([
            'scope_type' => 'firm_override',
            'scope_id' => $firm->id,
            'customer_price_cents' => 999,
            'reason' => 'negotiated discount',
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertSame($override->id, $result->id);
        $this->assertSame(999, $result->customer_price_cents);
    }

    public function test_package_default_wins_over_platform_default_but_loses_to_firm_override(): void
    {
        $plan = Plan::factory()->create();
        $firm = Firm::factory()->create();
        FirmLicense::factory()->forFirm($firm)->create(['plan_id' => $plan->id]);

        $platform = $this->rateCardRow(['customer_price_cents' => 25]);
        $package = $this->rateCardRow([
            'scope_type' => 'package_default',
            'scope_id' => $plan->id,
            'customer_price_cents' => 50,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        // package beats platform
        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);
        $this->assertSame($package->id, $result->id);
        $this->assertSame(50, $result->customer_price_cents);

        // firm_override beats package
        $override = $this->rateCardRow([
            'scope_type' => 'firm_override',
            'scope_id' => $firm->id,
            'customer_price_cents' => 5,
        ]);
        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);
        $this->assertSame($override->id, $result->id);
        $this->assertSame(5, $result->customer_price_cents);

        $this->assertSame($platform->scope_type, 'platform_default'); // sanity, unused var guard
    }

    public function test_a_package_default_row_for_a_different_plan_than_the_firms_own_is_never_selected(): void
    {
        $firmsPlan = Plan::factory()->create();
        $otherPlan = Plan::factory()->create();
        $firm = Firm::factory()->create();
        FirmLicense::factory()->forFirm($firm)->create(['plan_id' => $firmsPlan->id]);

        $this->rateCardRow(); // platform_default fallback
        $this->rateCardRow([
            'scope_type' => 'package_default',
            'scope_id' => $otherPlan->id,
            'customer_price_cents' => 12345,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertSame('platform_default', $result->scope_type);
        $this->assertNotSame(12345, $result->customer_price_cents);
    }

    public function test_a_firm_override_row_for_a_different_firm_is_never_selected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->rateCardRow(); // platform_default
        $this->rateCardRow([
            'scope_type' => 'firm_override',
            'scope_id' => $firmB->id,
            'customer_price_cents' => 1,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firmA);

        $this->assertSame('platform_default', $result->scope_type);
    }

    // ------------------------------------------------------------
    // Effective-dating
    // ------------------------------------------------------------

    public function test_a_closed_historical_row_is_never_selected_for_the_current_as_of_date(): void
    {
        $firm = Firm::factory()->create();
        $historical = $this->rateCardRow([
            'customer_price_cents' => 10,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subMonths(6),
        ]);
        $current = $this->rateCardRow([
            'customer_price_cents' => 20,
            'effective_from' => now()->subMonths(6),
            'effective_to' => null,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertSame($current->id, $result->id);
        $this->assertNotSame($historical->id, $result->id);
    }

    public function test_resolving_as_of_a_historical_date_picks_the_row_that_was_open_then(): void
    {
        $firm = Firm::factory()->create();
        $historical = $this->rateCardRow([
            'customer_price_cents' => 10,
            'effective_from' => Carbon::parse('2025-01-01'),
            'effective_to' => Carbon::parse('2025-06-01'),
        ]);
        $current = $this->rateCardRow([
            'customer_price_cents' => 20,
            'effective_from' => Carbon::parse('2025-06-01'),
            'effective_to' => null,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $asOfHistorical = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm, Carbon::parse('2025-03-01'));
        $asOfCurrent = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm, Carbon::parse('2025-09-01'));

        $this->assertSame($historical->id, $asOfHistorical->id);
        $this->assertSame($current->id, $asOfCurrent->id);
    }

    public function test_a_row_not_yet_effective_is_never_selected(): void
    {
        $firm = Firm::factory()->create();
        $this->rateCardRow([
            'customer_price_cents' => 999,
            'effective_from' => now()->addYear(),
            'effective_to' => null,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertNull($result);
    }

    public function test_a_null_priced_row_is_returned_as_is_never_coalesced_to_zero(): void
    {
        $firm = Firm::factory()->create();
        $this->rateCardRow([
            'provider_cost_cents' => null,
            'customer_price_cents' => null,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertNotNull($result);
        $this->assertNull($result->customer_price_cents);
        $this->assertNull($result->provider_cost_cents);
    }

    public function test_a_row_for_a_different_environment_is_never_selected(): void
    {
        $firm = Firm::factory()->create();
        $this->rateCardRow(['environment' => 'sandbox', 'customer_price_cents' => 0]);
        $production = $this->rateCardRow(['environment' => 'production', 'customer_price_cents' => 25]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertSame($production->id, $result->id);
    }

    public function test_a_row_for_a_different_product_or_billing_operation_is_never_selected(): void
    {
        $firm = Firm::factory()->create();
        $this->rateCardRow(['product' => 'balance', 'billing_operation' => 'get']);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');

        $result = $this->resolver->resolve(ProviderKey::Plaid, $classification, 'production', $firm);

        $this->assertNull($result);
    }
}
