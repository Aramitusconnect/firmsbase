<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\IntegrationRequeueAuditLogger;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IntegrationOutboxConcurrentClaimTest — Checkpoint 6 outbox
 * timestamp-precision race remediation (agent-r4-test-design.md test
 * 7; agent-r5-remediation-design-review.md §5.2). Proves
 * IntegrationOutboxEventService::claim()'s `FOR UPDATE SKIP LOCKED`
 * double-claim safety against TWO REAL, separate physical database
 * connections/transactions racing the same row — orthogonal to the
 * clock-source fix itself (this property is untouched by the
 * `now()` -> `statement_timestamp()` substitution), but grouped here
 * per R4/R5's file layout since it is complementary coverage for the
 * same remediation.
 *
 * Deliberately does NOT use RefreshDatabase. As
 * IntegrationOutboxTransactionDurabilityTest.php's own docblock
 * documents (this codebase's established convention): RefreshDatabase
 * wraps an entire test method in one continuously-open, never-committed
 * outer transaction, so a literal second physical DB connection would
 * see nothing at all — a genuine second session racing for `FOR UPDATE
 * SKIP LOCKED` on the same row requires the row to actually be visible
 * to that other session, which requires a real commit. This test uses
 * plain setUp()/tearDown() with real, committed fixture writes, and
 * deletes its own fixtures in tearDown() (deleting the created Firm row
 * is sufficient — firm_integrations.firm_id and
 * integration_outbox_events.firm_id both cascadeOnDelete() per the
 * migration; `firms` itself is the root tenant table and carries no
 * RLS policy, so no tenant context is required to delete it) to keep
 * the suite's overall database state clean without relying on
 * rollback.
 *
 * LOAD-BEARING INVARIANT (matches the sibling
 * IntegrationOutboxTimestampPrecisionTest.php docblock): claim()'s
 * eligibility/stale-lock decisions are made exclusively against
 * PostgreSQL's own statement_timestamp(), never PHP's Carbon/now() —
 * irrelevant to this file's proof (no timestamp boundary is probed
 * here), but restated for anyone reading this file in isolation.
 */
class IntegrationOutboxConcurrentClaimTest extends TestCase
{
    private IntegrationOutboxEventService $service;

    private ?Firm $firm = null;

    private ?FirmIntegration $firmIntegration = null;

    private ?IntegrationOutboxEvent $event = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IntegrationOutboxEventService(new WebhookRetryPolicyService(), new TimelineEventRecorder(), new IntegrationRequeueAuditLogger());

        // firms carries no RLS policy, so it can be created directly with
        // no tenant context active.
        $this->firm = Firm::factory()->create();

        $this->firmIntegration = $this->runWithFirmContext(
            $this->firm,
            fn () => FirmIntegration::factory()->forFirm($this->firm)->create(),
        );

        $this->event = $this->runWithFirmContext(
            $this->firm,
            fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($this->firmIntegration)->create(),
        );
    }

    protected function tearDown(): void
    {
        // Belt-and-braces: make sure no leftover open transaction from a
        // failed assertion mid-test holds a lock across test boundaries.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (array_key_exists('worker_b', config('database.connections', []))) {
            while (DB::connection('worker_b')->transactionLevel() > 0) {
                DB::connection('worker_b')->rollBack();
            }
            DB::purge('worker_b');
        }

        if ($this->firm !== null) {
            // firms carries no RLS policy — no tenant context required.
            // Cascades to firm_integrations and integration_outbox_events.
            DB::table('firms')->where('id', $this->firm->id)->delete();
        }

        parent::tearDown();
    }

    public function test_two_concurrent_worker_connections_racing_the_same_row_result_in_exactly_one_successful_claim(): void
    {
        $firm = $this->firm;
        $event = $this->event;

        // Register a second, independent Laravel DB connection pointing
        // at the SAME physical database/credentials as the default
        // 'pgsql' connection, purely at test runtime.
        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        // --- Connection A (default) -----------------------------------
        // Explicit DB::beginTransaction() (not the auto-committing
        // DB::transaction(closure)) — the transaction must stay open
        // across the switch to connection B.
        DB::beginTransaction();
        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

        $claimA = $this->service->claim($firm->id, 10);

        $this->assertCount(1, $claimA, 'Connection A must successfully claim the single pending row.');
        $tokenA = $claimA->first()->lock_token;
        $this->assertNotNull($tokenA);
        $this->assertSame($event->id, $claimA->first()->id);

        // Connection A's transaction is DELIBERATELY left open
        // (uncommitted) here — this is the entire point of the test: the
        // row's FOR UPDATE lock, acquired as part of claim()'s own CTE,
        // is still held by an in-flight, uncommitted transaction while
        // connection B attempts to claim the same row.

        // --- Connection B (worker_b) ------------------------------------
        DB::connection('worker_b')->beginTransaction();
        DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

        // Issue the exact CTE text from
        // IntegrationOutboxEventService::claim() (copied verbatim as of
        // this file's writing — if claim()'s SQL changes, this literal
        // copy must be updated to match) directly against connection B,
        // since IntegrationOutboxEventService itself always executes
        // against the default connection via the DB facade.
        $lockTokenB = (string) Str::uuid();
        $staleLockMinutes = 15;

        $rowsB = DB::connection('worker_b')->select(
            'WITH candidate AS ('.
            '  SELECT id FROM integration_outbox_events '.
            '  WHERE firm_id = ? AND ('.
            '    (status = ? AND next_attempt_at <= statement_timestamp()) '.
            "    OR (status = ? AND locked_at <= statement_timestamp() - (? || ' minutes')::interval)".
            '  ) '.
            '  ORDER BY next_attempt_at ASC, id ASC LIMIT ? '.
            '  FOR UPDATE SKIP LOCKED'.
            ') '.
            'UPDATE integration_outbox_events '.
            'SET status = ?, lock_token = ?, locked_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))), attempts = attempts + 1 '.
            'WHERE id IN (SELECT id FROM candidate) '.
            'RETURNING *',
            [
                $firm->id,
                'pending',
                'processing', $staleLockMinutes,
                10,
                'processing', $lockTokenB,
            ]
        );

        // Deterministic, not a race: SKIP LOCKED deterministically skips
        // a row locked by another session's still-open transaction. This
        // requires no timing at all — only that A's transaction genuinely
        // began and executed its UPDATE before B's SELECT ... FOR UPDATE
        // SKIP LOCKED runs, which is guaranteed by plain sequential PHP
        // statement ordering in this test method.
        $this->assertCount(0, $rowsB, 'Connection B must claim zero rows — the row is locked by connection A\'s still-open transaction.');

        DB::connection('worker_b')->rollBack();

        // Now commit connection A's transaction.
        DB::commit();

        // Fresh read, after A's commit, on a plain, uninvolved read
        // (default connection, no open transaction) — proves the final
        // state reflects exactly one successful claim.
        $finalRow = DB::table('integration_outbox_events')->where('id', $event->id)->first();

        $this->assertSame('processing', $finalRow->status);
        $this->assertSame($tokenA, $finalRow->lock_token, 'The final lock_token must be connection A\'s token — connection B claimed nothing.');
        $this->assertSame(1, (int) $finalRow->attempts, 'attempts must be exactly 1 — proving B genuinely claimed nothing rather than silently double-incrementing.');

        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
    }
}
