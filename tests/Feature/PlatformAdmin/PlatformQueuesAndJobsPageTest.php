<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\DeleteFailedJobAction;
use App\Filament\Actions\Platform\RetryFailedJobAction;
use App\Filament\Pages\PlatformQueuesAndJobsPage;
use App\Models\FailedJob;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\QueueHealthService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformQueuesAndJobsPageTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Navigation, direct-route auth,
 * filters, ordering, pagination, empty state, exception-summary
 * rendering (never the raw trace), and the full Retry/Delete action
 * lifecycle.
 */
final class PlatformQueuesAndJobsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function insertFailedJob(array $overrides = []): FailedJob
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert(array_merge([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'attempts' => 1]),
            'exception' => "RuntimeException: boom\n#0 /app/Foo.php(1): bar()",
            'failed_at' => now(),
        ], $overrides));

        return FailedJob::query()->where('uuid', $uuid)->firstOrFail();
    }

    // --- Navigation + auth ---

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformQueuesAndJobsPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformQueuesAndJobsPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformQueuesAndJobsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformQueuesAndJobsPage::getUrl())->assertOk();
    }

    // --- Exception summary rendering ---

    public function test_exception_summary_never_renders_the_full_raw_trace(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->insertFailedJob();

        $response = $this->get(PlatformQueuesAndJobsPage::getUrl());
        $response->assertOk();
        $response->assertSee('RuntimeException: boom');
        $response->assertDontSee('/app/Foo.php');
    }

    // --- Empty state ---

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformQueuesAndJobsPage::getUrl());
        $response->assertOk();
        $response->assertSee('No failed jobs');
    }

    // --- Filters ---

    public function test_queue_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $defaultJob = $this->insertFailedJob(['queue' => 'default']);
        $highJob = $this->insertFailedJob(['queue' => 'high']);

        $test = Livewire::test(PlatformQueuesAndJobsPage::class);
        $test->filterTable('queue', 'high');

        $test->assertCanSeeTableRecords([$highJob]);
        $test->assertCanNotSeeTableRecords([$defaultJob]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_when_failed_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedTime = now();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->insertFailedJob(['failed_at' => $sharedTime])->id;
        }

        $first = Livewire::test(PlatformQueuesAndJobsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformQueuesAndJobsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame(collect($ids)->sortDesc()->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        for ($i = 0; $i < 30; $i++) {
            $this->insertFailedJob();
        }

        $test = Livewire::test(PlatformQueuesAndJobsPage::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Retry action lifecycle ---

    public function test_retry_action_is_allowed_for_a_super_admin_and_writes_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $job = $this->insertFailedJob();

        $test = Livewire::test(PlatformQueuesAndJobsPage::class);
        $test->callTableAction(RetryFailedJobAction::getDefaultName(), $job);
        $test->assertHasNoTableActionErrors();

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $job->uuid)->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'failed_job_retried')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_retry_action_is_denied_for_a_security_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $job = $this->insertFailedJob();

        $test = Livewire::test(PlatformQueuesAndJobsPage::class);
        $test->callTableAction(RetryFailedJobAction::getDefaultName(), $job);

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $job->uuid)->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // --- Delete action lifecycle ---

    public function test_delete_action_is_allowed_for_a_super_admin_and_writes_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $job = $this->insertFailedJob();

        $test = Livewire::test(PlatformQueuesAndJobsPage::class);
        $test->callTableAction(DeleteFailedJobAction::getDefaultName(), $job);
        $test->assertHasNoTableActionErrors();

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $job->uuid)->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'Delete must never re-dispatch the job.');

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'failed_job_deleted')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_delete_action_is_denied_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        // BillingAdmin does not even pass canAccessOperations(), so this
        // is denied even before reaching the mutating action's own gate.
        $this->actingAs($admin, 'platform_admin');

        $job = $this->insertFailedJob();

        $response = $this->get(PlatformQueuesAndJobsPage::getUrl());
        $response->assertForbidden();

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $job->uuid)->count());
    }

    // --- TOCTOU safety ---

    public function test_retrying_an_already_deleted_job_returns_false_rather_than_throwing(): void
    {
        // Service-level proof that the action's own TOCTOU guard
        // (QueueHealthService::retryFailedJob()'s `if ($job === null)
        // return false;` branch) is what protects the UI layer above it
        // — see QueueHealthServiceRetryDeleteTest for the direct-service
        // version of this same proof. Exercised here once more via the
        // exact service instance the Filament Action itself resolves
        // from the container, to prove no divergent binding exists.
        $queueHealth = app(QueueHealthService::class);

        $job = $this->insertFailedJob();
        DB::table('failed_jobs')->where('uuid', $job->uuid)->delete();

        $this->assertFalse($queueHealth->retryFailedJob($job->uuid));
        $this->assertFalse($queueHealth->deleteFailedJob($job->uuid));
    }
}
