<?php

namespace Tests\Feature\Tenancy;

use App\Enums\DeploymentMode;
use App\Models\Firm;
use App\Models\Organization;
use App\Services\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TenantContextResolver;
    }

    protected function tearDown(): void
    {
        // Critical: the resolver holds static state across the whole
        // PHP process. Never let a test leak an active context into
        // whichever test runs next.
        TenantContextResolver::clear();
        parent::tearDown();
    }

    public function test_resolve_for_firm_produces_matching_context(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->forOrganization($organization)->create([
            'deployment_mode' => DeploymentMode::Saas,
        ]);

        $context = $this->resolver->resolveForFirm($firm);

        $this->assertSame($firm->id, $context->firmId);
        $this->assertSame($firm->uuid, $context->firmUuid);
        $this->assertSame($organization->id, $context->organizationId);
        $this->assertSame(DeploymentMode::Saas, $context->deploymentMode);
    }

    public function test_no_context_active_by_default(): void
    {
        $this->assertFalse(TenantContextResolver::hasContext());
        $this->assertNull(TenantContextResolver::current());
    }

    public function test_activate_for_firm_sets_current_context(): void
    {
        $firm = Firm::factory()->create();

        $context = $this->resolver->activateForFirm($firm);

        $this->assertTrue(TenantContextResolver::hasContext());
        $this->assertTrue($context->equals(TenantContextResolver::current()));
        $this->assertSame($firm->id, TenantContextResolver::current()->firmId);
    }

    public function test_clear_removes_active_context(): void
    {
        $firm = Firm::factory()->create();
        $this->resolver->activateForFirm($firm);

        TenantContextResolver::clear();

        $this->assertFalse(TenantContextResolver::hasContext());
        $this->assertNull(TenantContextResolver::current());
    }

    public function test_set_overwrites_previous_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->resolver->activateForFirm($firmA);
        $this->resolver->activateForFirm($firmB);

        $this->assertSame($firmB->id, TenantContextResolver::current()->firmId);
    }

    public function test_context_equals_compares_by_firm_id(): void
    {
        $firm = Firm::factory()->create();

        $first = $this->resolver->resolveForFirm($firm);
        $second = $this->resolver->resolveForFirm($firm->fresh());

        $this->assertTrue($first->equals($second));
    }

    /**
     * Regression test for the Plaid Anomaly Oversight 500 (Admin
     * acceptance audit): a Firm loaded with a restricted column list
     * that omits deployment_mode must fail loudly and clearly here,
     * never with a bare TypeError several frames away from the real
     * cause. firms.deployment_mode is NOT NULL DEFAULT 'saas' at the
     * schema level, so a fully-loaded, persisted Firm can never
     * legitimately reach this branch — null here only ever means the
     * caller's query omitted the column.
     */
    public function test_resolve_for_firm_throws_a_clear_error_when_deployment_mode_was_not_selected(): void
    {
        $firm = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated]);

        $partiallyLoadedFirm = Firm::query()->find($firm->id, ['id', 'uuid', 'name']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot resolve TenantContext for firm #{$firm->id}: deployment_mode was not loaded");

        $this->resolver->resolveForFirm($partiallyLoadedFirm);
    }

    public function test_resolve_for_firm_succeeds_for_every_normal_deployment_mode(): void
    {
        foreach (DeploymentMode::cases() as $mode) {
            $firm = Firm::factory()->create(['deployment_mode' => $mode]);

            $context = $this->resolver->resolveForFirm($firm);

            $this->assertSame($mode, $context->deploymentMode);
        }
    }
}
