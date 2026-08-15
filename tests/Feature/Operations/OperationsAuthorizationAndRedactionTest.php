<?php

namespace Tests\Feature\Operations;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformBackupsPage;
use App\Filament\Pages\PlatformDeploymentConfigsPage;
use App\Filament\Pages\PlatformOperationsOverviewPage;
use App\Filament\Pages\PlatformQueuesAndJobsPage;
use App\Filament\Pages\PlatformSchedulerPage;
use App\Filament\Pages\PlatformServiceHealthPage;
use App\Filament\Pages\PlatformStatusPageEventsPage;
use App\Filament\Resources\PlatformFleetMigrationRunResource;
use App\Filament\Resources\PlatformIncidentResource;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operations Control Plane — cross-cutting authorization and
 * redaction, applied uniformly to every Operations surface including
 * the new Overview.
 *
 * The Overview is the highest-risk page for a gap of this kind: it
 * aggregates every domain onto one screen, so a missing gate there
 * would leak more than a gap on any individual page.
 */
class OperationsAuthorizationAndRedactionTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    /**
     * Every Operations surface, by URL.
     *
     * @return array<string, array{0: string}>
     */
    public static function operationsUrls(): array
    {
        return [
            'overview' => [PlatformOperationsOverviewPage::class],
            'service health' => [PlatformServiceHealthPage::class],
            'queues and jobs' => [PlatformQueuesAndJobsPage::class],
            'scheduler' => [PlatformSchedulerPage::class],
            'backups' => [PlatformBackupsPage::class],
            'status page' => [PlatformStatusPageEventsPage::class],
            'dedicated deployments' => [PlatformDeploymentConfigsPage::class],
        ];
    }

    #[DataProvider('operationsUrls')]
    public function test_a_guest_cannot_reach_any_operations_page(string $page): void
    {
        $this->get($page::getUrl())->assertRedirect();
    }

    #[DataProvider('operationsUrls')]
    public function test_an_admin_with_no_operations_role_is_forbidden(string $page): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get($page::getUrl())->assertForbidden();
    }

    #[DataProvider('operationsUrls')]
    public function test_a_billing_admin_is_forbidden_from_operations(string $page): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);

        $this->actingAs($admin, 'platform_admin')->get($page::getUrl())->assertForbidden();
    }

    #[DataProvider('operationsUrls')]
    public function test_a_super_admin_can_reach_every_operations_page(string $page): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')->get($page::getUrl())->assertOk();
    }

    /**
     * Resources are split into one assertion per test rather than
     * switching identity mid-test: re-authenticating as a second
     * actor inside a single request cycle leaves panel/session state
     * from the first, which is a test artefact rather than anything
     * real about authorization.
     */
    public function test_the_incident_resource_is_closed_to_an_unprivileged_admin(): void
    {
        $unprivileged = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($unprivileged, 'platform_admin')
            ->get(PlatformIncidentResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_incident_resource_is_open_to_an_operations_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformIncidentResource::getUrl('index'))->assertOk();
    }

    public function test_the_fleet_resource_is_closed_to_an_unprivileged_admin(): void
    {
        $unprivileged = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($unprivileged, 'platform_admin')
            ->get(PlatformFleetMigrationRunResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_fleet_resource_is_open_to_an_operations_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformFleetMigrationRunResource::getUrl('index'))->assertOk();
    }

    // --- Redaction ---

    /**
     * A failed job's payload routinely contains serialized model
     * state, and its exception can contain a connection string. The
     * console shows the job class and the first line of the exception
     * and nothing else.
     */
    public function test_a_failed_job_payload_and_stack_trace_are_never_rendered(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SyncClientDocuments',
                'data' => [
                    'command' => 'O:24:"App\\Jobs\\SyncClientDocuments":2:{s:6:"secret";s:26:"AKIAIOSFODNN7EXAMPLESECRET";}',
                    'client_ssn' => '123-45-6789',
                ],
            ]),
            'exception' => "PDOException: SQLSTATE[08006] connection failed\n".
                "#0 pgsql:host=10.0.4.12;dbname=firmsbase;password=SuperSecretDbPassword\n".
                '#1 /app/vendor/frame.php',
            'failed_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformQueuesAndJobsPage::getUrl());

        $response->assertOk();

        // Safe metadata is shown.
        $response->assertSee('SyncClientDocuments');
        $response->assertSee('PDOException: SQLSTATE[08006] connection failed');

        // Everything sensitive is not.
        $response->assertDontSee('AKIAIOSFODNN7EXAMPLESECRET');
        $response->assertDontSee('123-45-6789');
        $response->assertDontSee('SuperSecretDbPassword');
        $response->assertDontSee('10.0.4.12');
        $response->assertDontSee('/app/vendor/frame.php');
    }

    public function test_the_overview_does_not_render_queue_payloads_or_exceptions(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\X', 'data' => ['token' => 'tok_live_SENSITIVE']]),
            'exception' => 'RuntimeException: host=10.0.9.9 password=hunter2',
            'failed_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertDontSee('tok_live_SENSITIVE');
        $response->assertDontSee('hunter2');
        $response->assertDontSee('10.0.9.9');
    }

    /**
     * The overview aggregates from many tables; none of the summary
     * counters may carry a raw credential-bearing string through.
     */
    public function test_the_overview_renders_only_aggregate_counts_for_queues(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Queues observed');
        $response->assertDontSee('payload');
    }
}
