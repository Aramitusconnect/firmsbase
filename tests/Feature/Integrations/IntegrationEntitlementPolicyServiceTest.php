<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\ValueObjects\IntegrationAccessDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationEntitlementPolicyServiceTest — Checkpoint 9 (frozen design
 * §5). Mirrors the shape WebhookEntitlementPolicyService itself is
 * built to (no standalone WebhookEntitlementPolicyServiceTest exists
 * in this codebase to copy structurally, so this file follows the
 * established ExpensesDisabledBlocksAllServicesTest/EntitlementServiceTest
 * convention instead: real EntitlementService, real
 * EntitlementSource::AdminOverride writes via setForSource(), no
 * mocking). Proves isEnabled()/evaluate()/assertEnabled() all behave
 * correctly for enabled/disabled firms, and that the module_catalog
 * seed row this checkpoint's own migration adds is correctly keyed.
 */
class IntegrationEntitlementPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlements;

    private IntegrationEntitlementPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->policy = new IntegrationEntitlementPolicyService($this->entitlements);
    }

    // ------------------------------------------------------------
    // module_catalog seed row
    // ------------------------------------------------------------

    public function test_the_integration_module_catalog_row_exists_and_is_correctly_keyed(): void
    {
        $row = DB::table('module_catalog')->where('module_code', 'integration')->first();

        $this->assertNotNull($row, 'The 2026_09_08_082001_seed_integration_module_catalog_entry migration must seed exactly this row.');
        $this->assertSame('Integrations', $row->module_name);
        $this->assertSame('plan_control', $row->category);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_the_integration_module_code_is_separate_from_webhook_and_api(): void
    {
        $integration = DB::table('module_catalog')->where('module_code', 'integration')->first();
        $webhook = DB::table('module_catalog')->where('module_code', 'webhook')->first();

        $this->assertNotNull($integration);
        $this->assertNotNull($webhook);
        $this->assertNotSame($integration->module_code, $webhook->module_code);
    }

    // ------------------------------------------------------------
    // isEnabled() / evaluate() / assertEnabled() — disabled by default
    // ------------------------------------------------------------

    public function test_a_firm_with_no_entitlement_row_is_disabled_by_default(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->policy->isEnabled($firm));
    }

    public function test_evaluate_denies_with_a_reason_when_disabled(): void
    {
        $firm = Firm::factory()->create();

        $decision = $this->policy->evaluate($firm);

        $this->assertInstanceOf(IntegrationAccessDecision::class, $decision);
        $this->assertFalse($decision->allowed);
        $this->assertNotNull($decision->reason);
        $this->assertSame('The integration entitlement is not enabled for this firm.', $decision->reason);
    }

    public function test_assert_enabled_throws_a_runtime_exception_when_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The integration entitlement is not enabled for this firm.');

        $this->policy->assertEnabled($firm);
    }

    // ------------------------------------------------------------
    // Enabled via AdminOverride
    // ------------------------------------------------------------

    public function test_a_firm_with_an_admin_override_grant_is_enabled(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $this->assertTrue($this->policy->isEnabled($firm));
    }

    public function test_evaluate_allows_with_no_reason_when_enabled(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $decision = $this->policy->evaluate($firm);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_assert_enabled_is_a_noop_when_enabled(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $this->policy->assertEnabled($firm);
        $this->addToAssertionCount(1); // no exception == pass
    }

    public function test_disabling_after_enabling_correctly_flips_isenabled_back_to_false(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->assertTrue($this->policy->isEnabled($firm));

        $this->entitlements->setForSource($firm, 'integration', EntitlementSource::AdminOverride, false);
        $this->assertFalse($this->policy->isEnabled($firm));
    }

    public function test_entitlement_is_per_firm_never_leaking_across_firms(): void
    {
        $enabledFirm = Firm::factory()->create();
        $disabledFirm = Firm::factory()->create();
        $this->entitlements->setForSource($enabledFirm, 'integration', EntitlementSource::AdminOverride, true);

        $this->assertTrue($this->policy->isEnabled($enabledFirm));
        $this->assertFalse($this->policy->isEnabled($disabledFirm));
    }

    // ------------------------------------------------------------
    // Module code isolation: enabling 'webhook' does not enable
    // 'integration', and vice versa.
    // ------------------------------------------------------------

    public function test_enabling_webhook_entitlement_does_not_enable_integration_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'webhook', EntitlementSource::AdminOverride, true);

        $this->assertFalse($this->policy->isEnabled($firm));
    }
}
