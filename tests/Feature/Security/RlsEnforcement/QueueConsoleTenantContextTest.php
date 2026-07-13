<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\Support\TenantAwareJobContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QueueConsoleTenantContextTest — Section 39A.
 *
 * Queue-job pattern: proves TenantAwareJobContext (a trait a job class
 * can `use`) requires an EXPLICIT firm_id/Firm — never guessed from a
 * bare model id inside the job body — and correctly isolates tenant
 * data through the same TenantContextService mechanism. Deliberately
 * NOT retrofitted onto the 4 existing job classes in this pass (see
 * the trait's own docblock) — this proves the pattern in isolation so
 * future queue work has a tested precedent to follow.
 *
 * Console/global-maintenance pattern: no app/Console/Commands directory
 * exists in this repository (confirmed by direct inspection) — there is
 * no existing command to retrofit or rewrite. This proves the expected
 * pattern a future global-maintenance command MUST follow instead of
 * silently bypassing RLS: iterate firms explicitly and run each firm's
 * work inside that firm's own tenant context, never a single unscoped
 * pass over a tenant-owned table.
 */
class QueueConsoleTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_custom_console_commands_exist_that_could_silently_bypass_rls(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Console/Commands'));
    }

    public function test_tenant_aware_job_context_trait_requires_an_explicit_firm_not_a_guessed_model_id(): void
    {
        $job = new class
        {
            use TenantAwareJobContext;
        };

        $reflection = new \ReflectionMethod($job, 'runInFirmContext');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('firm', $parameters[0]->getName());
        $this->assertSame('callback', $parameters[1]->getName());
    }

    public function test_tenant_aware_job_context_correctly_isolates_tenant_data_per_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = Client::factory()->forFirm($firmA)->create();
        $clientB = Client::factory()->forFirm($firmB)->create();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        $visibleUnderA = $job->runInFirmContext($firmA, fn () => Client::withoutGlobalScopes()->pluck('id')->all());
        $visibleUnderB = $job->runInFirmContext($firmB, fn () => Client::withoutGlobalScopes()->pluck('id')->all());

        $this->assertSame([$clientA->id], $visibleUnderA);
        $this->assertSame([$clientB->id], $visibleUnderB);
    }

    public function test_global_maintenance_pattern_iterates_firms_explicitly_rather_than_bypassing_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Client::factory()->forFirm($firmA)->create();
        Client::factory()->forFirm($firmB)->create();

        // ClientFactory deliberately leaves the database tenant context
        // set to the last-created row's firm after create() returns
        // (see its own docblock) — clear that baseline so the "no
        // context is active outside the loop" assertion below proves
        // what it actually claims to, rather than passing by accident.
        (new TenantContextService())->clearDatabaseTenantContext();

        DB::statement('ALTER TABLE clients FORCE ROW LEVEL SECURITY');

        $job = new class
        {
            use TenantAwareJobContext;
        };

        // The expected pattern for a future global-maintenance command:
        // iterate every firm explicitly and scope each pass to that
        // firm's own context — never one unscoped query across all
        // firms at once.
        $countsPerFirm = [];

        foreach (Firm::withoutGlobalScopes()->get() as $firm) {
            $countsPerFirm[$firm->id] = $job->runInFirmContext(
                $firm,
                fn () => Client::withoutGlobalScopes()->count(),
            );
        }

        $this->assertSame(1, $countsPerFirm[$firmA->id]);
        $this->assertSame(1, $countsPerFirm[$firmB->id]);

        // Outside any per-firm iteration, no context is active — an
        // unscoped read must never silently return every firm's rows.
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }
}
