<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderOperationPolicyResolver;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException;
use App\Integrations\Models\ProviderFirmOperationPolicy;
use App\Integrations\Models\ProviderKillSwitch;
use App\Integrations\Models\ProviderOperationDefaultPolicy;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProviderOperationPolicyResolverTest — checkpoint4-design-cost-control.md
 * §2 step 7 / §4.3; checkpoint4-combined-design.md §1.8's coordinator-
 * resolved two-table split. Proves:
 *   - the firm-scoped `provider_firm_operation_policies` row overrides
 *     the platform-default `provider_operation_default_policies` row,
 *     per-field, and the resolver falls back to the default when no
 *     firm-scoped row exists at all;
 *   - platform-scope kill switches at all three levels (product,
 *     endpoint_category, operation) block with
 *     `ProviderKillSwitchActiveException`, checked broad to narrow;
 *   - a firm's own `optional_operation_suspended` flag on
 *     `provider_firm_operation_policies` (never on
 *     `provider_kill_switches`, which is admin-only/platform-scope
 *     only) blocks an OPTIONAL classification with
 *     `ProviderOptionalOperationSuspendedException`, and never blocks a
 *     never-optional classification (item/transactions).
 */
class ProviderOperationPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProviderOperationPolicyResolver $resolver;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderOperationPolicyResolver();
        $this->classifier = new ProviderBillingClassifier();
    }

    private function defaultPolicy(array $overrides = []): ProviderOperationDefaultPolicy
    {
        return ProviderOperationDefaultPolicy::query()->create(array_merge([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'soft_limit_quantity' => 100,
            'hard_limit_quantity' => 200,
            'limit_window_seconds' => 86400,
            'cooldown_seconds' => 30,
            'cache_ttl_seconds' => 3600,
        ], $overrides));
    }

    // ------------------------------------------------------------
    // Policy inheritance / fallback
    // ------------------------------------------------------------

    public function test_falls_back_to_the_platform_default_when_no_firm_row_exists(): void
    {
        $firm = Firm::factory()->create();
        $this->defaultPolicy();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');

        $this->assertSame(100, $policy->softLimitQuantity);
        $this->assertSame(200, $policy->hardLimitQuantity);
        $this->assertSame(30, $policy->cooldownSeconds);
        $this->assertSame(3600, $policy->cacheTtlSeconds);
    }

    public function test_a_firm_specific_policy_row_overrides_the_platform_default(): void
    {
        $firm = Firm::factory()->create();
        $this->defaultPolicy(['hard_limit_quantity' => 200, 'soft_limit_quantity' => 100]);
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'hard_limit_quantity' => 5,
            'soft_limit_quantity' => 2,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');

        $this->assertSame(2, $policy->softLimitQuantity);
        $this->assertSame(5, $policy->hardLimitQuantity);
    }

    public function test_the_firm_override_is_per_field_falling_back_to_default_for_unset_fields(): void
    {
        $firm = Firm::factory()->create();
        $this->defaultPolicy(['cooldown_seconds' => 45, 'cache_ttl_seconds' => 900, 'hard_limit_quantity' => 200]);
        // Firm row only overrides hard_limit_quantity; every other
        // column is left NULL, which must fall through to the default
        // row's value per-field, not per-row.
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'hard_limit_quantity' => 3,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');

        $this->assertSame(3, $policy->hardLimitQuantity);
        $this->assertSame(45, $policy->cooldownSeconds);
        $this->assertSame(900, $policy->cacheTtlSeconds);
    }

    public function test_another_firms_policy_row_never_leaks_into_this_firms_resolution(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->defaultPolicy(['hard_limit_quantity' => 200]);
        $this->createWithFirmContext($firmB, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firmB->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'hard_limit_quantity' => 1,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firmA, 'production');

        $this->assertSame(200, $policy->hardLimitQuantity);
    }

    public function test_hard_fallback_defaults_apply_when_neither_row_exists(): void
    {
        $firm = Firm::factory()->create();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');

        $this->assertNull($policy->softLimitQuantity);
        $this->assertNull($policy->hardLimitQuantity);
        $this->assertSame(86400, $policy->limitWindowSeconds);
        $this->assertNull($policy->cacheTtlSeconds);
    }

    // ------------------------------------------------------------
    // Kill switches — platform scope, broad to narrow
    // ------------------------------------------------------------

    public function test_a_platform_product_level_kill_switch_blocks_the_operation(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'statements',
            'suspended' => true,
            'reason' => 'incident: statements endpoint outage',
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $this->expectException(ProviderKillSwitchActiveException::class);

        $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
    }

    public function test_a_platform_endpoint_category_level_kill_switch_blocks_every_product_in_that_category(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY,
            'target' => 'core_banking_data',
            'suspended' => true,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'auth', 'get');

        $this->expectException(ProviderKillSwitchActiveException::class);

        $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
    }

    public function test_a_platform_operation_level_kill_switch_blocks_only_that_exact_operation(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_OPERATION,
            'target' => 'balance:get',
            'suspended' => true,
        ]);

        $blocked = $this->classifier->classify(ProviderKey::Plaid, 'balance', 'get');
        $this->expectException(ProviderKillSwitchActiveException::class);
        $this->resolver->resolve(ProviderKey::Plaid, $blocked, $firm, 'production');
    }

    public function test_an_operation_level_kill_switch_does_not_block_a_different_operation(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_OPERATION,
            'target' => 'balance:get',
            'suspended' => true,
        ]);
        $unaffected = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        // Should resolve without throwing.
        $policy = $this->resolver->resolve(ProviderKey::Plaid, $unaffected, $firm, 'production');
        $this->assertNotNull($policy);
    }

    public function test_a_suspended_false_kill_switch_row_does_not_block_anything(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'statements',
            'suspended' => false,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
        $this->assertNotNull($policy);
    }

    public function test_a_kill_switch_for_a_different_provider_key_does_not_block_plaid(): void
    {
        $firm = Firm::factory()->create();
        ProviderKillSwitch::query()->create([
            'provider_key' => 'some_other_provider',
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'statements',
            'suspended' => true,
        ]);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
        $this->assertNotNull($policy);
    }

    // ------------------------------------------------------------
    // Firm-level optional-operation suspension — lives on
    // provider_firm_operation_policies, NOT provider_kill_switches.
    // ------------------------------------------------------------

    public function test_a_firms_own_optional_operation_suspension_blocks_that_product_for_that_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'optional_operation_suspended' => true,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $this->assertTrue($classification->isOptional);

        $this->expectException(ProviderOptionalOperationSuspendedException::class);

        $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
    }

    public function test_a_firms_optional_operation_suspension_never_leaks_to_another_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createWithFirmContext($firmB, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firmB->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'optional_operation_suspended' => true,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firmA, 'production');
        $this->assertNotNull($policy);
    }

    public function test_a_firm_can_never_suspend_a_never_optional_product_via_this_mechanism(): void
    {
        $firm = Firm::factory()->create();
        // Even if a row exists with optional_operation_suspended=true
        // for 'transactions', ProviderBillingClassifier marks
        // 'transactions' as never-optional, so the resolver must never
        // throw ProviderOptionalOperationSuspendedException for it.
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'transactions',
            'environment' => 'production',
            'optional_operation_suspended' => true,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'transactions', 'sync');
        $this->assertFalse($classification->isOptional);

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
        $this->assertNotNull($policy);
    }

    public function test_optional_operation_suspended_false_never_blocks(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'optional_operation_suspended' => false,
        ]));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $policy = $this->resolver->resolve(ProviderKey::Plaid, $classification, $firm, 'production');
        $this->assertNotNull($policy);
    }
}
