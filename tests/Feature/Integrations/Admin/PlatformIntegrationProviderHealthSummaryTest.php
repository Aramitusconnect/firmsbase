<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\FirmActivationStatus;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\HealthStateService;
use App\Jobs\RefreshIntegrationPlatformProviderHealthSummaryJob;
use App\Models\Firm;
use App\Services\IntegrationPlatformProviderHealthSummaryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PlatformIntegrationProviderHealthSummaryTest — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center").
 * Proves IntegrationPlatformProviderHealthSummaryService's per-provider
 * cross-firm rollup is correct, that it is built by iterating each
 * firm's OWN tenant context (never a live, unscoped cross-firm query —
 * structurally required since integration_connection_health is
 * FORCE-RLS'd per firm), that the job/command wiring dispatches one job
 * per registered provider, that the scheduled cadence matches the
 * platform-overview refresh's own `everyFiveMinutes()->withoutOverlapping()`
 * precedent, and that the runtime database role never becomes
 * superuser/BYPASSRLS while any of this runs.
 */
final class PlatformIntegrationProviderHealthSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_for_provider_computes_correct_cross_firm_aggregate_counts(): void
    {
        $provider = IntegrationProvider::factory()->create();
        $otherProvider = IntegrationProvider::factory()->create();

        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $firmC = Firm::factory()->activated()->create();

        // Firm A: one active connection to $provider.
        $this->runWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->forProvider($provider)->create());

        // Firm B: one disconnected connection to $provider.
        $this->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->forProvider($provider)->disconnected()->create());

        // Firm C: a connection to a DIFFERENT provider — must never be
        // counted in $provider's own summary row.
        $this->runWithFirmContext($firmC, fn () => FirmIntegration::factory()->forFirm($firmC)->forProvider($otherProvider)->create());

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $provider->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($provider->code, $row->provider_code);
        $this->assertTrue((bool) $row->provider_enabled);
        $this->assertSame(1, $row->connected_firm_count);
        $this->assertSame(1, $row->disconnected_firm_count);

        $otherRow = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $otherProvider->id)
            ->first();
        $this->assertNull($otherRow, 'Only refreshForProvider($provider) ran — the other provider must have no row yet.');
    }

    public function test_refresh_for_provider_reflects_a_disabled_provider(): void
    {
        $provider = IntegrationProvider::factory()->create(['status' => 'deprecated']);

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $provider->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->provider_enabled);
        $this->assertSame(0, $row->connected_firm_count);
    }

    public function test_refresh_for_provider_flags_firms_requiring_attention_and_derives_oauth_health_signal(): void
    {
        $provider = IntegrationProvider::factory()->create();
        $firm = Firm::factory()->activated()->create();

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create()
        );

        $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->recordCredentialError(
            $connection->id,
            $firm->id,
            new SanitizedHealthDiagnostic(
                SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                SanitizedHealthDiagnostic::OPERATION_HEALTH_CHECK,
                401,
            )
        ));

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $provider->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, $row->firms_requiring_attention_count);
        $this->assertSame('action_required', $row->oauth_health_signal);

        $errorSummary = json_decode($row->recent_error_classification_summary, true);
        $this->assertIsArray($errorSummary);
        $this->assertSame(1, $errorSummary[SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR]);
    }

    public function test_a_second_refresh_for_the_same_provider_upserts_the_existing_row_instead_of_duplicating(): void
    {
        $provider = IntegrationProvider::factory()->create();

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());
        $firstRow = DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->first();

        $firm = Firm::factory()->activated()->create();
        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create());

        Carbon::setTestNow(now()->addMinute());
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());
        Carbon::setTestNow();

        $this->assertSame(
            1,
            DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->count()
        );

        $secondRow = DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->first();
        $this->assertSame($firstRow->id, $secondRow->id);
        $this->assertSame(1, $secondRow->connected_firm_count);
    }

    public function test_the_scheduled_command_dispatches_one_job_per_registered_provider(): void
    {
        Queue::fake();

        $providerA = IntegrationProvider::factory()->create();
        $providerB = IntegrationProvider::factory()->create();
        $seededTestProvider = IntegrationProvider::query()->where('code', 'test')->first();

        $this->artisan('integrations:platform-provider-health:refresh')->assertExitCode(0);

        Queue::assertPushed(
            RefreshIntegrationPlatformProviderHealthSummaryJob::class,
            fn (RefreshIntegrationPlatformProviderHealthSummaryJob $job) => $job->providerId === $providerA->id
        );
        Queue::assertPushed(
            RefreshIntegrationPlatformProviderHealthSummaryJob::class,
            fn (RefreshIntegrationPlatformProviderHealthSummaryJob $job) => $job->providerId === $providerB->id
        );

        if ($seededTestProvider !== null) {
            Queue::assertPushed(
                RefreshIntegrationPlatformProviderHealthSummaryJob::class,
                fn (RefreshIntegrationPlatformProviderHealthSummaryJob $job) => $job->providerId === $seededTestProvider->id
            );
        }
    }

    public function test_the_job_gracefully_no_ops_when_the_provider_no_longer_exists(): void
    {
        $job = new RefreshIntegrationPlatformProviderHealthSummaryJob(999999999);

        $job->handle(app(IntegrationPlatformProviderHealthSummaryService::class));
        $this->addToAssertionCount(1);

        $this->assertSame(
            0,
            DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', 999999999)->count()
        );
    }

    public function test_the_command_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        // bootstrap/app.php's ->withSchedule() callback is registered
        // via Illuminate\Console\Application::starting() (see
        // ApplicationBuilder::withSchedule()), which only actually runs
        // once a genuine console Application is bootstrapped — never
        // merely by resolving Schedule::class cold from the container.
        // Artisan::call() forces that bootstrap synchronously.
        Artisan::call('about');

        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'integrations:platform-provider-health:refresh'));

        $this->assertNotNull($event, 'The integrations:platform-provider-health:refresh command must be scheduled in bootstrap/app.php.');
        $this->assertSame('*/5 * * * *', $event->expression, 'Must run every five minutes, matching the platform-overview refresh cadence.');
        $this->assertTrue($event->withoutOverlapping, 'Must be registered ->withoutOverlapping(), matching the platform-overview refresh precedent.');
    }

    public function test_computing_the_aggregate_never_elevates_the_runtime_role_to_superuser_or_bypassrls(): void
    {
        $provider = IntegrationProvider::factory()->create();

        $firms = Firm::factory()->activated()->count(3)->create();
        foreach ($firms as $firm) {
            $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create());
        }

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::selectOne('select rolbypassrls, rolsuper from pg_roles where rolname = current_user');
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls, 'The runtime role must not have BYPASSRLS after computing a cross-firm provider health aggregate.');
        $this->assertFalse((bool) $row->rolsuper, 'The runtime role must not be a Postgres superuser after computing a cross-firm provider health aggregate.');

        $this->assertSame(
            3,
            DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->value('connected_firm_count')
        );
    }

    public function test_only_activated_firms_are_iterated(): void
    {
        $provider = IntegrationProvider::factory()->create();

        $draftFirm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft]);
        $activatedFirm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($draftFirm, fn () => FirmIntegration::factory()->forFirm($draftFirm)->forProvider($provider)->create());
        $this->runWithFirmContext($activatedFirm, fn () => FirmIntegration::factory()->forFirm($activatedFirm)->forProvider($provider)->create());

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $this->assertSame(
            1,
            DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->value('connected_firm_count'),
            'A draft (not-yet-activated) firm\'s connection must not be counted.'
        );
    }
}
