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
        $this->resolver = new TenantContextResolver();
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
}
