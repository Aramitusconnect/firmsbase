<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\WebhookVerificationOutcome;
use App\Integrations\Services\InboundWebhookReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InboundWebhookReceiptRetentionFixTest — Checkpoint 8
 * (agent-8h-architecture-security-review.md §1 item 7/§2 item 9/§4.2;
 * diff-review.md §2 item 7). Proves the 7d/30d branch on
 * verification_outcome is now correct (a Verified receipt gets 30d,
 * everything else gets 7d), AND that the retention sweep's own
 * independent recomputation from verification_outcome/received_at
 * matches regardless of what's stored in retention_deadline
 * (defense-in-depth — neither layer substitutes for the other).
 */
class InboundWebhookReceiptRetentionFixTest extends TestCase
{
    use RefreshDatabase;

    private InboundWebhookReceiptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InboundWebhookReceiptService();

        // Deliberately NEVER uses Carbon::setTestNow() here: the
        // defense-in-depth sweep tests below compare PHP-fixture-built
        // received_at/retention_deadline values against PostgreSQL's
        // OWN live statement_timestamp() (via
        // SweepIntegrationRetentionCommand's raw SQL) — a frozen
        // Carbon clock has and can have ZERO effect on that raw-SQL
        // comparison (matches IntegrationOutboxTimestampPrecisionTest's
        // own documented rule), and would silently desynchronize
        // PHP-side "now" from the real DB server clock across a long
        // session. Every timestamp fixture below uses real now().
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Layer 1 — the producer-side fix itself:
    // InboundWebhookReceiptService::recordReceipt()'s stored
    // retention_deadline correctly branches on verification_outcome.
    // ------------------------------------------------------------

    public function test_a_verified_receipt_gets_a_thirty_day_retention_deadline(): void
    {
        $receipt = $this->service->recordReceipt(
            providerKey: 'test',
            routingTokenHash: hash('sha256', (string) Str::uuid()),
            bodyHash: hash('sha256', (string) Str::uuid()),
            outcome: WebhookVerificationOutcome::Verified,
            requestCorrelationId: null,
            providerEventId: (string) Str::uuid(),
            signatureVersion: 'v1',
            providerTimestamp: now(),
            failureCode: null,
        );

        $this->assertSame(
            now()->addDays(30)->toDateTimeString(),
            $receipt->retention_deadline->toDateTimeString(),
            'A Verified receipt must get the frozen 30-day commitment, not the flat 7-day default this checkpoint fixes.'
        );
    }

    public function test_a_malformed_receipt_gets_the_seven_day_default(): void
    {
        $receipt = $this->service->recordReceipt(
            providerKey: 'test',
            routingTokenHash: hash('sha256', (string) Str::uuid()),
            bodyHash: hash('sha256', (string) Str::uuid()),
            outcome: WebhookVerificationOutcome::Malformed,
            requestCorrelationId: null,
            providerEventId: null,
            signatureVersion: 'v1',
            providerTimestamp: now(),
            failureCode: 'malformed_payload',
        );

        $this->assertSame(
            now()->addDays(7)->toDateTimeString(),
            $receipt->retention_deadline->toDateTimeString(),
            'Every non-Verified outcome must still use the existing 7-day default — this fix only changes the Verified branch.'
        );
    }

    public function test_the_verified_retention_days_config_key_is_independently_honored(): void
    {
        config(['integrations.webhook.receipt_verified_retention_days' => 45]);

        $receipt = $this->service->recordReceipt(
            providerKey: 'test',
            routingTokenHash: hash('sha256', (string) Str::uuid()),
            bodyHash: hash('sha256', (string) Str::uuid()),
            outcome: WebhookVerificationOutcome::Verified,
            requestCorrelationId: null,
            providerEventId: (string) Str::uuid(),
            signatureVersion: 'v1',
            providerTimestamp: now(),
            failureCode: null,
        );

        $this->assertSame(now()->addDays(45)->toDateTimeString(), $receipt->retention_deadline->toDateTimeString());
    }

    public function test_the_non_verified_retention_days_config_key_remains_independent_of_the_verified_key(): void
    {
        config([
            'integrations.webhook.receipt_verified_retention_days' => 90,
            'integrations.webhook.receipt_retention_days' => 3,
        ]);

        $verified = $this->service->recordReceipt(
            providerKey: 'test',
            routingTokenHash: hash('sha256', (string) Str::uuid()),
            bodyHash: hash('sha256', (string) Str::uuid()),
            outcome: WebhookVerificationOutcome::Verified,
            requestCorrelationId: null,
            providerEventId: (string) Str::uuid(),
            signatureVersion: 'v1',
            providerTimestamp: now(),
            failureCode: null,
        );

        $malformed = $this->service->recordReceipt(
            providerKey: 'test',
            routingTokenHash: hash('sha256', (string) Str::uuid()),
            bodyHash: hash('sha256', (string) Str::uuid()),
            outcome: WebhookVerificationOutcome::Malformed,
            requestCorrelationId: null,
            providerEventId: null,
            signatureVersion: 'v1',
            providerTimestamp: now(),
            failureCode: 'malformed_payload',
        );

        $this->assertSame(now()->addDays(90)->toDateTimeString(), $verified->retention_deadline->toDateTimeString());
        $this->assertSame(now()->addDays(3)->toDateTimeString(), $malformed->retention_deadline->toDateTimeString());
    }

    // ------------------------------------------------------------
    // Layer 2 — defense-in-depth: the retention sweep independently
    // recomputes eligibility from verification_outcome + received_at,
    // NEVER trusting the stored retention_deadline column, so a wrong
    // (or maliciously/accidentally corrupted) stored value cannot by
    // itself cause premature or delayed deletion.
    // ------------------------------------------------------------

    public function test_the_sweep_deletes_a_verified_receipt_past_thirty_days_even_though_its_stored_deadline_falsely_claims_it_is_not_yet_due(): void
    {
        $hash = hash('sha256', 'defense-in-depth-1-'.Str::uuid());
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => $hash,
            'body_hash' => hash('sha256', (string) Str::uuid()),
            'verification_outcome' => 'verified',
            'received_at' => now()->subDays(40), // genuinely 40 days old
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(40),
            'processing_handoff_status' => 'pending',
            // Deliberately WRONG/stale stored deadline, claiming this
            // row is not due for another decade — the sweep must
            // ignore this column entirely for its own eligibility
            // decision.
            'retention_deadline' => now()->addYears(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $exists = DB::table('integration_webhook_receipts')->where('routing_token_hash', $hash)->exists();
        $this->assertFalse(
            $exists,
            'The sweep must independently recompute eligibility from verification_outcome + received_at, never trusting a stale/wrong stored retention_deadline — a single layer being wrong must never be the sole determinant of a destructive operation.'
        );
    }

    public function test_the_sweep_keeps_a_verified_receipt_within_thirty_days_even_though_its_stored_deadline_falsely_claims_it_is_already_due(): void
    {
        $hash = hash('sha256', 'defense-in-depth-2-'.Str::uuid());
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => $hash,
            'body_hash' => hash('sha256', (string) Str::uuid()),
            'verification_outcome' => 'verified',
            'received_at' => now()->subDays(5), // genuinely recent
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(5),
            'processing_handoff_status' => 'pending',
            // Deliberately WRONG stored deadline claiming this row was
            // already due a year ago.
            'retention_deadline' => now()->subYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $exists = DB::table('integration_webhook_receipts')->where('routing_token_hash', $hash)->exists();
        $this->assertTrue(
            $exists,
            'The sweep must NOT delete a genuinely-recent Verified receipt just because its stored retention_deadline column falsely claims it is overdue — independent recomputation protects against both directions of a wrong stored value.'
        );
    }

    public function test_the_sweep_correctly_recomputes_the_seven_day_window_for_a_non_verified_receipt_regardless_of_stored_deadline(): void
    {
        $hash = hash('sha256', 'defense-in-depth-3-'.Str::uuid());
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => $hash,
            'body_hash' => hash('sha256', (string) Str::uuid()),
            'verification_outcome' => 'malformed',
            'failure_code' => 'malformed_payload',
            'received_at' => now()->subDays(10), // > 7d default
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(10),
            'processing_handoff_status' => 'pending',
            // Stored deadline falsely uses the 30-day VERIFIED window,
            // as if the pre-fix bug (or a data-corruption event) were
            // still in effect.
            'retention_deadline' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $exists = DB::table('integration_webhook_receipts')->where('routing_token_hash', $hash)->exists();
        $this->assertFalse(
            $exists,
            'A non-Verified receipt past its OWN correct 7-day window must be deleted by the sweep\'s independent recomputation, even if the stored column (wrongly) implies a later 30-day-shaped deadline.'
        );
    }
}
