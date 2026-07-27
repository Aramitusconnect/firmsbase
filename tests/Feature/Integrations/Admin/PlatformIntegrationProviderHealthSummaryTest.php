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
use Illuminate\Support\Str;
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

    // ------------------------------------------------------------
    // CHECKPOINT 1 (FirmsVault Live Integrations) additions —
    // checkpoint1-design-health-sandbox.md §A.3.2: the new rollup
    // fields, proven across a small multi-firm/multi-connection fixture
    // so the per-firm-loop accumulator's arithmetic is genuinely
    // exercised across more than one connection.
    // ------------------------------------------------------------

    public function test_the_new_rollup_fields_compute_correctly_across_a_multi_firm_multi_connection_fixture(): void
    {
        $provider = IntegrationProvider::factory()->create();

        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $firmC = Firm::factory()->activated()->create();

        $connectionA = $this->runWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->forProvider($provider)->create());
        $connectionB = $this->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->forProvider($provider)->create());
        $connectionC = $this->runWithFirmContext($firmC, fn () => FirmIntegration::factory()->forFirm($firmC)->forProvider($provider)->create());

        // Connection A: two successes (total_request_count=2,
        // total_success_count=2), latency 100ms.
        $this->runWithFirmContext($firmA, function () use ($connectionA, $firmA) {
            app(HealthStateService::class)->recordSuccess($connectionA->id, $firmA->id, latencyMs: 100);
            app(HealthStateService::class)->recordSuccess($connectionA->id, $firmA->id, latencyMs: 100);
        });

        // Connection B: one rate-limited failure (throttled), latency
        // 300ms.
        $this->runWithFirmContext($firmB, fn () => app(HealthStateService::class)->recordRateLimited(
            $connectionB->id,
            $firmB->id,
            now()->addMinute(),
            new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED, SanitizedHealthDiagnostic::OPERATION_PUSH_SYNC),
            latencyMs: 300,
        ));

        // Connection C: one token-refresh credential-error failure, no
        // latency measurement supplied (nullable, must not break the
        // average).
        $this->runWithFirmContext($firmC, fn () => app(HealthStateService::class)->recordCredentialError(
            $connectionC->id,
            $firmC->id,
            new SanitizedHealthDiagnostic(SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR, SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH),
        ));

        // A dead-lettered outbox event on connection A, and a second,
        // non-dead-lettered one that must NOT be counted.
        $this->runWithFirmContext($firmA, function () use ($connectionA, $firmA) {
            DB::table('integration_outbox_events')->insert([
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionA->id,
                'event_type' => 'test.resource.push_retry',
                'domain_event_id' => (string) Str::uuid(),
                'payload_json' => '{}',
                'status' => 'dead_lettered',
                'attempts' => 5,
                'max_attempts' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('integration_outbox_events')->insert([
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionA->id,
                'event_type' => 'test.resource.push_retry',
                'domain_event_id' => (string) Str::uuid(),
                'payload_json' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // A webhook-verification-failure row for this provider — must
        // be summed OUTSIDE the per-firm loop, into
        // webhook_verification_failure_count.
        DB::table('integration_webhook_verification_failures')->insert([
            'provider_code' => $provider->code,
            'failure_reason' => 'signature_mismatch',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // A stale one (outside the 24h window) — must NOT be counted.
        DB::table('integration_webhook_verification_failures')->insert([
            'provider_code' => $provider->code,
            'failure_reason' => 'unknown_routing_token',
            'occurred_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        // A row for a DIFFERENT provider — must not leak into this
        // provider's count.
        $otherProvider = IntegrationProvider::factory()->create();
        DB::table('integration_webhook_verification_failures')->insert([
            'provider_code' => $otherProvider->code,
            'failure_reason' => 'malformed_payload',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $provider->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row->total_request_count, '2 successes (A) + 1 rate-limited (B) + 1 credential-error (C) = 4 total requests across all three connections.');
        $this->assertSame(2, (int) $row->total_success_count, 'Only connection A\'s two recordSuccess() calls must count.');
        $this->assertSame(1, (int) $row->throttled_connection_count, 'Exactly one connection (B) has last_failure_category=rate_limited.');
        $this->assertSame(1, (int) $row->token_refresh_failure_count, 'Exactly one connection (C) has last_operation_label=token_refresh.');
        $this->assertSame(1, (int) $row->webhook_verification_failure_count, 'Exactly one webhook-verification-failure row falls within the 24h window for THIS provider — the stale row and the other provider\'s row must both be excluded.');
        $this->assertSame(1, (int) $row->dead_letter_count, 'Exactly one dead_lettered outbox event exists — the pending one must not be counted.');
        // avg over connection A's last_latency_ms=100 and connection
        // B's last_latency_ms=300 (connection C supplied none, so it
        // contributes no sample) = (100+300)/2 = 200.
        $this->assertSame(200, (int) $row->avg_latency_ms, 'avg_latency_ms must average only over connections that actually recorded a latency sample, never treating a null as zero.');
    }

    public function test_a_provider_with_zero_latency_samples_yields_a_null_avg_latency_never_a_fabricated_zero(): void
    {
        $provider = IntegrationProvider::factory()->create();
        $firm = Firm::factory()->activated()->create();

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create());

        // recordSuccess() with NO latencyMs argument at all.
        $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->recordSuccess($connection->id, $firm->id));

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $row = DB::table('integration_platform_provider_health_summaries')
            ->where('integration_provider_id', $provider->id)
            ->first();

        $this->assertNull($row->avg_latency_ms, 'With zero latency samples, avg_latency_ms must be null — never a fabricated 0, mirroring this service\'s own established "no data yet -> null" discipline for its other derived signals.');
        $this->assertSame(0, (int) $row->dead_letter_count);
        $this->assertSame(0, (int) $row->throttled_connection_count);
        $this->assertSame(0, (int) $row->token_refresh_failure_count);
        $this->assertSame(0, (int) $row->webhook_verification_failure_count);
    }

    public function test_a_second_refresh_recomputes_the_new_rollup_fields_from_scratch_rather_than_accumulating_across_runs(): void
    {
        $provider = IntegrationProvider::factory()->create();
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create());

        $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->recordSuccess($connection->id, $firm->id, latencyMs: 50));
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $firstRow = DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->first();
        $this->assertSame(1, (int) $firstRow->total_request_count);

        Carbon::setTestNow(now()->addMinute());
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());
        Carbon::setTestNow();

        $secondRow = DB::table('integration_platform_provider_health_summaries')->where('integration_provider_id', $provider->id)->first();
        $this->assertSame(
            1,
            (int) $secondRow->total_request_count,
            'total_request_count on THIS SUMMARY ROW must reflect the connection\'s own cumulative counter as of this refresh, not be double-counted or accumulated again across repeated refreshForProvider() calls (recordSuccess() itself was only called once).'
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
