<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantContextServiceTest — Section 39A. Proves TenantContextService's
 * own context lifecycle: cleared after runWithFirmContext() (both the
 * PHP-memory layer and the PostgreSQL session/transaction setting),
 * and does not leak between independent operations (each test's own
 * RefreshDatabase transaction already guarantees no leak between
 * TESTS; this proves no leak WITHIN a single test/process either,
 * which is the harder guarantee — the one that matters for a reused
 * queue worker or Octane process).
 */
class TenantContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextService $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = new TenantContextService();
    }

    public function test_has_firm_context_is_false_before_any_context_is_set(): void
    {
        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_set_firm_context_activates_php_memory_context(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->setFirmContext($firm);

        $this->assertTrue($this->tenantContext->hasFirmContext());
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());

        $this->tenantContext->clearFirmContext();
    }

    public function test_set_firm_context_accepts_a_firm_instance_an_int_id_or_a_uuid_string(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->setFirmContext($firm);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();

        $this->tenantContext->setFirmContext($firm->id);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();

        $this->tenantContext->setFirmContext($firm->uuid);
        $this->assertSame($firm->id, $this->tenantContext->currentFirmId());
        $this->tenantContext->clearFirmContext();
    }

    public function test_clear_firm_context_deactivates_php_memory_context(): void
    {
        $firm = Firm::factory()->create();
        $this->tenantContext->setFirmContext($firm);

        $this->tenantContext->clearFirmContext();

        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_tenant_context_is_cleared_after_run_with_firm_context_php_memory_layer(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext->runWithFirmContext($firm, fn () => null);

        $this->assertFalse($this->tenantContext->hasFirmContext());
        $this->assertNull($this->tenantContext->currentFirmId());
    }

    public function test_tenant_context_is_cleared_after_run_with_firm_context_database_layer(): void
    {
        $firmA = Firm::factory()->create();
        // ClientFactory deliberately leaves the database tenant context
        // set to the created row's firm after create() returns (see its
        // own docblock) — clear that baseline first so this test proves
        // what it actually claims to prove: that runWithFirmContext()
        // itself does not leak forward when there was no ambient
        // context active before it was called. (Nesting into an
        // existing ambient context is covered separately by
        // TenantContextServiceSessionScopedNestingTest, where the
        // correct behavior is to restore, not clear, that context.)
        $clientA = Client::factory()->forFirm($firmA)->create();
        $this->tenantContext->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $this->tenantContext->runWithFirmContext($firmA, fn () => Client::withoutGlobalScopes()->count());

        // After the call returns, the database session setting must be
        // cleared — an unscoped read (no context) must see 0 rows,
        // proving nothing leaked forward.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_tenant_context_does_not_leak_between_two_sequential_run_with_firm_context_calls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $visibleUnderA = $this->tenantContext->runWithFirmContext(
            $firmA,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $visibleUnderB = $this->tenantContext->runWithFirmContext(
            $firmB,
            fn () => Client::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$clientA->id], $visibleUnderA);
        $this->assertSame([$clientB->id], $visibleUnderB, 'Firm A context must not leak into the Firm B call.');
    }

    public function test_context_is_cleared_even_when_the_callback_throws(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->tenantContext->runWithFirmContext($firm, function () {
                throw new \RuntimeException('deliberate test failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($this->tenantContext->hasFirmContext(), 'Context must be cleared even when the callback throws.');
    }

    public function test_set_database_tenant_context_throws_when_no_php_memory_context_is_active(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->tenantContext->setDatabaseTenantContext();
    }

    public function test_clear_database_tenant_context_is_safe_to_call_with_no_active_context(): void
    {
        $this->tenantContext->clearDatabaseTenantContext();

        $this->assertFalse($this->tenantContext->hasFirmContext());
    }
}
