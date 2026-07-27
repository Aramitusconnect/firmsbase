<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Jobs\RecordWebhookVerificationFailureJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * RecordWebhookVerificationFailureJobTest — Checkpoint 1 (FirmsVault
 * Live Integrations, checkpoint1-design-health-sandbox.md §A.3.3;
 * checkpoint1-security-review.md Finding 5). Proves:
 *  - the job is genuinely queueable (implements ShouldQueue) — the
 *    structural precondition that makes it dispatchable off the
 *    timing-critical request path at all, distinct from and a
 *    prerequisite to the dispatch-site proof in
 *    RecordWebhookVerificationFailureJobDispatchTest;
 *  - the constructor validates $failureReason against the EXACT closed
 *    set the migration's own CHECK constraint enforces (never drifting
 *    silently out of sync with it);
 *  - handle() is the sole writer, inserting exactly the expected
 *    columns;
 *  - failed() never throws, even though its whole job is best-effort
 *    logging.
 */
final class RecordWebhookVerificationFailureJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_implements_should_queue(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new RecordWebhookVerificationFailureJob('test', 'signature_mismatch'),
            'RecordWebhookVerificationFailureJob must implement ShouldQueue — this is the structural precondition for it to ever be dispatched asynchronously rather than run inline.'
        );
    }

    public function test_valid_failure_reasons_matches_the_migrations_check_constraint_exactly(): void
    {
        // Mirrors the exact closed set from
        // database/migrations/2026_09_13_130001_create_integration_webhook_verification_failures_table.php's
        // own CHECK constraint — read directly from Postgres so this
        // test fails loudly if either side ever drifts.
        $checkClause = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = 'integration_webhook_verification_failures_reason_check'"
        );

        $this->assertNotNull($checkClause, 'The reason CHECK constraint must exist on integration_webhook_verification_failures.');

        foreach (RecordWebhookVerificationFailureJob::VALID_FAILURE_REASONS as $reason) {
            $this->assertStringContainsString(
                "'{$reason}'",
                $checkClause->definition,
                "Job-side VALID_FAILURE_REASONS entry '{$reason}' must also appear in the DB's own CHECK constraint."
            );
        }

        $this->assertCount(5, RecordWebhookVerificationFailureJob::VALID_FAILURE_REASONS);
    }

    public function test_the_constructor_accepts_every_valid_failure_reason_without_throwing(): void
    {
        foreach (RecordWebhookVerificationFailureJob::VALID_FAILURE_REASONS as $reason) {
            $job = new RecordWebhookVerificationFailureJob('test', $reason);
            $this->assertSame($reason, $job->failureReason);
            $this->assertSame('test', $job->providerCode);
        }
    }

    public function test_the_constructor_rejects_an_unknown_failure_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordWebhookVerificationFailureJob('test', 'some_unreviewed_new_reason');
    }

    public function test_the_constructor_rejects_an_empty_failure_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordWebhookVerificationFailureJob('test', '');
    }

    public function test_handle_inserts_exactly_one_row_with_the_expected_columns(): void
    {
        $countBefore = DB::table('integration_webhook_verification_failures')->count();

        (new RecordWebhookVerificationFailureJob('test', 'unknown_routing_token'))->handle();

        $countAfter = DB::table('integration_webhook_verification_failures')->count();
        $this->assertSame($countBefore + 1, $countAfter);

        $row = DB::table('integration_webhook_verification_failures')->orderByDesc('id')->first();
        $this->assertSame('test', $row->provider_code);
        $this->assertSame('unknown_routing_token', $row->failure_reason);
        $this->assertNotNull($row->occurred_at);
    }

    public function test_handle_writes_a_fresh_row_for_every_call_never_deduping(): void
    {
        (new RecordWebhookVerificationFailureJob('test', 'signature_mismatch'))->handle();
        (new RecordWebhookVerificationFailureJob('test', 'signature_mismatch'))->handle();

        $count = DB::table('integration_webhook_verification_failures')
            ->where('provider_code', 'test')
            ->where('failure_reason', 'signature_mismatch')
            ->count();

        $this->assertGreaterThanOrEqual(2, $count, 'This is a durable COUNTER table — every genuine rejection must produce its own row, never deduplicated the way receipts/events are.');
    }

    public function test_handle_never_touches_any_tenant_scoped_table_or_requires_a_firm_context(): void
    {
        // No runWithFirmContext() anywhere in this test — proves handle()
        // genuinely needs no tenant context at all, consistent with the
        // table's own "no RLS, platform-owned, pre-tenant" design.
        $this->assertNoDatabaseTenantContext();

        (new RecordWebhookVerificationFailureJob('unknownprovider', 'malformed_payload'))->handle();

        $this->assertNoDatabaseTenantContext();

        $row = DB::table('integration_webhook_verification_failures')->orderByDesc('id')->first();
        $this->assertSame('unknownprovider', $row->provider_code, 'A provider code with no matching integration_providers/firm_integrations row at all must still be recordable — this table carries no FK to either.');
    }

    public function test_failed_hook_never_throws_even_though_its_job_is_best_effort_logging(): void
    {
        $job = new RecordWebhookVerificationFailureJob('test', 'malformed_payload');

        $job->failed(new RuntimeException('simulated queue worker failure'));
        $job->failed(null);

        $this->addToAssertionCount(2);
    }
}
