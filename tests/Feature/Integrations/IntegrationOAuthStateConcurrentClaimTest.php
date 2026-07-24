<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOAuthState;
use App\Models\Firm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationOAuthStateConcurrentClaimTest — Checkpoint 13 (frozen-test-
 * closure-plan.md §4; agent-13h §4 concurrency proofs, domain 33). Proves
 * IntegrationOAuthStateService::claimAndDecrypt()'s one-time consumption
 * against TWO REAL, separate physical database connections/transactions
 * racing the SAME state token: exactly one succeeds.
 *
 * claimAndDecrypt()'s atomicity boundary is a single, primary-key-
 * targeted `UPDATE integration_oauth_states SET consumed_at = now() WHERE
 * id = ? AND consumed_at IS NULL AND expires_at > now() RETURNING *` —
 * NOT a SKIP-LOCKED CTE. So a second connection racing the SAME row BLOCKS
 * on PostgreSQL's native single-row lock rather than skipping it (exactly
 * like SyncItemService::claimForRetry(), NOT like
 * IntegrationOutboxEventService::claim()). A short lock_timeout on the
 * racing (worker_b) connection turns that indefinite block into a
 * deterministic, bounded failure — no sleep()/usleep() anywhere.
 *
 * The one-time consumption proven here is orthogonal to the deferred
 * finding #4 (this method's `now()` -> `statement_timestamp()`
 * substitution): the row-lock exclusion is independent of the clock
 * source, so this proof holds regardless of that separate change.
 *
 * Deliberately does NOT use RefreshDatabase — identical rationale to
 * IntegrationOutboxConcurrentClaimTest / SyncRetryPollJobTest: a genuine
 * second physical connection can only see committed rows, which
 * RefreshDatabase's single never-committed outer transaction would hide.
 * Every fixture is a real committed row, deleted in tearDown() via
 * cascadeOnDelete() from `firms`.
 */
class IntegrationOAuthStateConcurrentClaimTest extends TestCase
{
    /** @var int[] */
    private array $createdFirmIds = [];

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (array_key_exists('worker_b', config('database.connections', []))) {
            while (DB::connection('worker_b')->transactionLevel() > 0) {
                DB::connection('worker_b')->rollBack();
            }
            DB::purge('worker_b');
        }
        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);

        if ($this->createdFirmIds !== []) {
            DB::table('firms')->whereIn('id', $this->createdFirmIds)->delete();
        }

        parent::tearDown();
    }

    private function firm(): Firm
    {
        $firm = Firm::factory()->create();
        $this->createdFirmIds[] = $firm->id;

        return $firm;
    }

    /**
     * The EXACT atomic one-time-claim statement claimAndDecrypt() executes
     * (copied verbatim as of this file's writing — if that method's SQL
     * changes, this literal copy must be updated to match). The decrypt
     * step that follows in the real method is post-claim and outside the
     * atomicity/race boundary this test proves.
     */
    private const CLAIM_SQL =
        'UPDATE integration_oauth_states '.
        'SET consumed_at = now() '.
        'WHERE id = ? AND consumed_at IS NULL AND expires_at > now() '.
        'RETURNING *';

    public function test_two_concurrent_connections_racing_the_same_state_token_result_in_exactly_one_successful_consumption(): void
    {
        $firm = $this->firm();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        // Default factory row: unconsumed (consumed_at NULL) and unexpired
        // (expires_at now+10m) — genuinely claimable.
        $state = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()->forFirmIntegration($connection)->create());

        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        $lockTimeoutMessage = null;

        try {
            // --- Connection A (default) --------------------------------
            DB::beginTransaction();
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

            $claimedA = DB::selectOne(self::CLAIM_SQL, [$state->id]);

            $this->assertNotNull($claimedA, 'Connection A must successfully consume the single unconsumed state row.');
            $this->assertNotNull($claimedA->consumed_at, 'Connection A\'s claim must have stamped consumed_at.');

            // A's transaction is DELIBERATELY left open (uncommitted) — the
            // row's UPDATE lock is still held while B races the same row.

            // --- Connection B (worker_b) -------------------------------
            DB::connection('worker_b')->beginTransaction();
            DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);
            DB::connection('worker_b')->statement("SET LOCAL lock_timeout = '200ms'");

            try {
                DB::connection('worker_b')->selectOne(self::CLAIM_SQL, [$state->id]);

                $this->fail('Connection B\'s consumption attempt must be blocked by connection A\'s held row lock and time out — it must never succeed while A\'s transaction is still open.');
            } catch (QueryException $e) {
                $lockTimeoutMessage = strtolower($e->getMessage());
            }

            $this->assertStringContainsString(
                'lock timeout',
                $lockTimeoutMessage,
                'Connection B\'s attempt must fail specifically with PostgreSQL\'s lock_timeout error, proving it was genuinely blocked by A\'s held row lock — not skipped, not a different error.'
            );

            DB::connection('worker_b')->rollBack();
            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (array_key_exists('worker_b', config('database.connections', []))) {
                while (DB::connection('worker_b')->transactionLevel() > 0) {
                    DB::connection('worker_b')->rollBack();
                }
            }
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
        }

        // Fresh read after A's commit — exactly one consumption took effect.
        $final = $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')->where('id', $state->id)->first());
        $this->assertNotNull($final->consumed_at, 'The final state must be consumed — connection A\'s claim, and only A\'s, took effect.');

        // Complementary sequential proof: a SECOND claim on the now-consumed
        // row returns nothing — the token can never be consumed twice,
        // concurrently OR sequentially.
        $secondClaim = $this->runWithFirmContext($firm, fn () => DB::selectOne(self::CLAIM_SQL, [$state->id]));
        $this->assertNull($secondClaim, 'A state token already consumed must never be claimable again — one-time consumption holds sequentially too.');
    }
}
