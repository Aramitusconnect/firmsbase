<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\RenewGraphSubscriptionJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\Support\NonBillableWebhookStubProvider;
use Tests\TestCase;
use Throwable;

/**
 * RenewalCycleIdentityMatrixTest — Checkpoint 8.2 ("webhook-renewal cycle
 * identity" mission). Adds the renewal-cycle-identity proofs the session
 * mapping found were NOT yet covered by `RenewGraphSubscriptionJobDurableGateTest`
 * / `RenewGraphSubscriptionJobIdempotencyKeyTest` / `GmailMailboxRoutingLifecycleTest`
 * (day rollover, reconnect-produces-a-new-identity at the durable-gate row
 * level specifically, concurrent-worker race, cross-firm isolation, secret
 * containment, and reconciliation-cannot-be-bypassed-by-a-fresh-attempt).
 *
 * Does NOT re-prove what those three files already establish (minute
 * rollover, backoff-sequence sharing, provider-success/local-failure
 * resume, 404-fallback-subscribe, ambiguous-outcome escalation, definite-
 * rejection retry, no-transaction/no-lock, tenant-context restoration) —
 * see this file's own class docblock cross-references instead of
 * duplicating those tests here.
 */
final class RenewalCycleIdentityMatrixTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    private NonBillableWebhookStubProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        Cache::flush();

        $this->provider = new NonBillableWebhookStubProvider;
        $this->app->instance(NonBillableWebhookStubProvider::class, $this->provider);

        config(['integrations.providers' => [
            ProviderKey::Microsoft365->value => NonBillableWebhookStubProvider::class,
        ]]);
    }

    private function firm(): Firm
    {
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $durable = DB::connection(self::DURABLE_CONNECTION);

            $durable->transaction(function () use ($durable, $firm) {
                $durable->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                $durable->table('timeline_events')->where('firm_id', $firm->id)->delete();
            });

            Firm::on(self::DURABLE_CONNECTION)->where('id', $firm->id)->delete();
        });

        return $firm;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['status' => ConnectionStatus::Active->value]));
    }

    private function subscription(Firm $firm, FirmIntegration $connection): IntegrationProviderWebhookSubscription
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()
            ->forFirmIntegration($connection)
            ->create([
                'provider_key' => ProviderKey::Microsoft365->value,
                'provider_subscription_id' => 'graph-subscription-original',
                'expires_at' => now()->addHours(2),
                'status' => ProviderWebhookSubscriptionStatus::Active->value,
            ]));
    }

    private function runJob(Firm $firm, FirmIntegration $connection, IntegrationProviderWebhookSubscription $subscription): ?Throwable
    {
        try {
            (new RenewGraphSubscriptionJob($connection->id, $firm->id, $subscription->id))
                ->handle(app(ProviderRegistry::class), app(HealthStateService::class));

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** @return list<object> */
    private function attemptRows(Firm $firm): array
    {
        return DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', $firm->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ------------------------------------------------------------
    // Phase 5 item 3: day rollover.
    // ------------------------------------------------------------

    public function test_the_same_renewal_cycle_produces_the_same_key_across_a_day_rollover(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->provider->onRenew = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'renewSubscription');
        };

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $firstKey = $this->attemptRows($firm)[0]->logical_operation_key;

        // Cross midnight and then some — the token has no wall-clock
        // component at all, so a day boundary must be exactly as inert
        // as a minute boundary.
        $this->travel(25)->hours();

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $rows = $this->attemptRows($firm);

        $this->assertCount(1, $rows, 'A day rollover must not fork the cycle into a second row.');
        $this->assertSame($firstKey, $rows[0]->logical_operation_key);
        $this->assertSame(2, (int) $rows[0]->total_send_count);
    }

    // ------------------------------------------------------------
    // Phase 5 items 10/11: reconnect / revoked-and-recreated
    // subscriptions must never collide with an old cycle's durable row.
    // ------------------------------------------------------------

    public function test_a_new_connection_after_full_disconnect_gets_a_durable_row_that_never_collides_with_the_old_connections_finalized_cycle(): void
    {
        $firm = $this->firm();

        $connectionA = $this->connection($firm);
        $subscriptionA = $this->subscription($firm, $connectionA);
        $this->assertNull($this->runJob($firm, $connectionA, $subscriptionA));

        $rowsAfterA = $this->attemptRows($firm);
        $this->assertCount(1, $rowsAfterA);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $rowsAfterA[0]->attempt_state);

        // A full disconnect + reconnect always mints a brand-new
        // FirmIntegration row (finishCallback() unconditionally refuses
        // to complete OAuth against an already-Disconnected row — see
        // ProviderConnectionService::disconnect()'s own docblock) and,
        // with it, a brand-new IntegrationProviderWebhookSubscription
        // row. Simulated directly here since this test is about the
        // durable-gate row identity, not the OAuth callback plumbing
        // (already covered end-to-end by
        // GmailMailboxRoutingLifecycleTest::test_reconnecting_the_same_mailbox_after_a_full_disconnect_replaces_rather_than_duplicates_the_route).
        $connectionB = $this->connection($firm);
        $subscriptionB = $this->subscription($firm, $connectionB);

        $this->assertNull($this->runJob($firm, $connectionB, $subscriptionB));

        $allRows = $this->attemptRows($firm);
        $this->assertCount(2, $allRows, 'The new connection lifecycle must get its OWN durable row, not reuse the old one.');
        $this->assertNotSame($allRows[0]->logical_operation_key, $allRows[1]->logical_operation_key);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $allRows[1]->attempt_state);
        $this->assertSame(1, (int) $allRows[1]->send_count, 'The new lifecycle starts its own fresh generation, never inheriting the old one\'s counters.');
    }

    // ------------------------------------------------------------
    // Phase 5 item 13: two concurrent renewal workers race the same
    // cycle — exactly one provider invocation. The durable gate's CAS
    // (`claim()`'s single autocommitted UPDATE ... WHERE) is what
    // actually makes this safe under real parallelism; simulated here by
    // invoking claim() a second time while the first worker's lease is
    // still live, the same proof convention
    // ProviderOperationAttemptServiceTest::test_a_second_claim_while_the_first_lease_is_live_is_refused_not_granted
    // already establishes at the service level. This test proves it at
    // the JOB level for a renewal specifically.
    // ------------------------------------------------------------

    public function test_a_second_renewal_worker_racing_the_same_cycle_never_calls_the_provider(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        // Worker A: markAttemptStarted() has already committed on the
        // independent connection by the time this closure runs (it
        // happens BEFORE the provider call — see
        // ProviderConnectionService::callGatedProviderOperation()), so
        // worker A's lease is genuinely live at this point. Worker B's
        // tick for the identical logical cycle must find the row
        // `InFlightElsewhere` and quietly decline to call the provider
        // at all — not throw, not proceed, not fork a second row.
        $this->provider->onRenew = function () use ($firm, $connection, $subscription): array {
            // Worker A's own call has already incremented renewCalls to
            // 1 (renewSubscription() counts before invoking this
            // closure) — the assertion is that worker B's race adds NO
            // further call on top of it.
            $countBeforeB = $this->provider->renewCalls;
            $resultFromB = $this->runJob($firm, $connection, $subscription);
            $this->assertNull($resultFromB, 'A concurrent worker refused via InFlightElsewhere must not surface as a job failure.');
            $this->assertSame($countBeforeB, $this->provider->renewCalls, 'Worker B must not have called the provider at all while A\'s lease is live.');

            return [
                'subscription_id' => 'graph-subscription-renewed',
                'expires_at' => now()->addHours(70)->toIso8601String(),
            ];
        };

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $this->assertSame(
            1,
            $this->provider->renewCalls,
            'Exactly one of the two racing workers may have actually called the provider.'
        );
        $rows = $this->attemptRows($firm);
        $this->assertCount(1, $rows, 'One logical cycle produces exactly one durable row, however many workers raced it.');
    }

    // ------------------------------------------------------------
    // Phase 5 item 16: cross-firm isolation.
    // ------------------------------------------------------------

    public function test_two_firms_renewing_at_the_same_moment_never_share_or_inspect_each_others_operation_key(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();

        $connectionA = $this->connection($firmA);
        $subscriptionA = $this->subscription($firmA, $connectionA);
        $connectionB = $this->connection($firmB);
        $subscriptionB = $this->subscription($firmB, $connectionB);

        $this->assertNull($this->runJob($firmA, $connectionA, $subscriptionA));
        $this->assertNull($this->runJob($firmB, $connectionB, $subscriptionB));

        $rowsA = $this->attemptRows($firmA);
        $rowsB = $this->attemptRows($firmB);

        $this->assertCount(1, $rowsA);
        $this->assertCount(1, $rowsB);
        $this->assertNotSame($rowsA[0]->logical_operation_key, $rowsB[0]->logical_operation_key);
        $this->assertStringStartsWith('firm_'.$firmA->id.':', $rowsA[0]->logical_operation_key);
        $this->assertStringStartsWith('firm_'.$firmB->id.':', $rowsB[0]->logical_operation_key);
    }

    // ------------------------------------------------------------
    // Phase 5 item 15: an uncertain cycle cannot be bypassed by minting
    // a "new" key — because the token is recomputed fresh, every time,
    // purely from the subscription row's own persisted state, and
    // nothing writes to that state except a gate-verified success.
    // ------------------------------------------------------------

    public function test_a_reconciliation_required_cycle_cannot_be_bypassed_by_a_fresh_attempt_while_the_subscription_row_is_unchanged(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->provider->onRenew = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'renewSubscription');
        };

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired->value, $this->attemptRows($firm)[0]->attempt_state);

        // Even travelling well past the subscription's own expiry (the
        // kind of wall-clock drift that could tempt a naive design into
        // minting a "new" cycle) changes nothing: the token depends only
        // on the persisted row id, provider_subscription_id and
        // expires_at, none of which a stuck reconciliation touches.
        $this->travel(10)->days();

        $secondAttempt = $this->runJob($firm, $connection, $subscription);
        $this->assertNotNull($secondAttempt, 'A cycle stuck in reconciliation_required must never be silently bypassed by a fresh-looking attempt.');
        $this->assertSame(1, $this->provider->renewCalls, 'No second provider call may ever be made against an unresolved cycle.');
        $this->assertCount(1, $this->attemptRows($firm), 'Still exactly one durable row — no parallel key was minted to route around it.');
    }

    // ------------------------------------------------------------
    // Phase 5 item 17: no operation key or durable metadata column ever
    // carries a raw token/secret shape.
    // ------------------------------------------------------------

    public function test_no_renewal_operation_key_or_durable_metadata_contains_a_token_shaped_value(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $secretLikeToken = 'ya29.SECRET-ACCESS-TOKEN-SHOULD-NEVER-APPEAR-ANYWHERE';
        $this->provider->onRenew = static function () use ($secretLikeToken): array {
            // A defensive/malicious provider response could try to smuggle
            // token-shaped content back; the recovery-evidence redaction
            // must strip it to the two safe fields regardless.
            return [
                'subscription_id' => 'graph-subscription-renewed',
                'expires_at' => now()->addHours(70)->toIso8601String(),
                'access_token' => $secretLikeToken,
            ];
        };

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $row = $this->attemptRows($firm)[0];

        foreach ((array) $row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertStringNotContainsString(
                $secretLikeToken,
                $value,
                "provider_operation_attempts.{$column} must never carry a token-shaped value."
            );
        }

        $evidence = json_decode((string) $row->redacted_result_metadata, true);
        $this->assertSame(['provider_subscription_id', 'expires_at'], array_keys($evidence), 'Recovery evidence must be limited to exactly these two safe fields, dropping anything else the provider returned.');
    }
}
