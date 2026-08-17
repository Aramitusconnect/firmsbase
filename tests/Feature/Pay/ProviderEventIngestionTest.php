<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\ProviderOutcome;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Services\Pay\Data\FakeProviderEvent;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\ProviderCommandExecutorService;
use App\Services\Pay\ProviderEventIngestionService;
use App\Services\Pay\ProviderResourceOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\TestCase;

/**
 * FV-A3-020 (sequential shape), FV-A3-030 … FV-A3-036 — inbound
 * provider event ingestion. CERTIFICATION BLOCKING throughout
 * (v1.4 §22, §24-§29).
 */
class ProviderEventIngestionTest extends TestCase
{
    use BuildsPayFixtures, RefreshDatabase;

    private function ingestion(): ProviderEventIngestionService
    {
        return app(ProviderEventIngestionService::class);
    }

    private function executor(): ProviderCommandExecutorService
    {
        return app(ProviderCommandExecutorService::class);
    }

    /**
     * @return array{0: Firm, 1: PaymentAttempt, 2: IntegrationProvider, 3: FirmIntegration}
     */
    private function capturedViaProvider(): array
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        $this->executor()->execute($this->payCommandOf($attempt));

        $captured = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));

        return [$firm, $captured, $provider, $connection];
    }

    private function successEvent(PaymentAttempt $attempt, int $providerId, string $providerKey, string $eventId, array $overrides = []): FakeProviderEvent
    {
        return new FakeProviderEvent(
            integrationProviderId: $overrides['provider_id'] ?? $providerId,
            providerKey: $providerKey,
            eventId: $eventId,
            resourceType: $overrides['resource_type'] ?? 'payment',
            resourceReference: $overrides['resource'] ?? (string) $attempt->provider_reference,
            outcome: $overrides['outcome'] ?? ProviderOutcome::Succeeded,
            amountCents: (int) $attempt->amount_cents,
            environment: $overrides['environment'] ?? 'sandbox',
            presentedFirmIntegrationId: $overrides['presented'] ?? null,
        );
    }

    /**
     * FV-A3-020 (sequential shape) — the synchronous success response
     * has already been applied; the provider's own success EVENT for the
     * same economic fact then arrives. One terminal result, one journal,
     * one ownership relationship. The genuinely-concurrent version is
     * ProviderRaceCertificationTest.
     */
    public function test_fv_a3_020_sync_response_then_provider_event_is_single_effect(): void
    {
        [$firm, $attempt, $provider, $connection] = $this->capturedViaProvider();

        $result = $this->ingestion()->ingest(
            $this->successEvent($attempt, (int) $provider->id, $provider->code, 'evt-sync-race-1'),
        );

        $this->assertSame(ProviderEventIngestionService::PROCESSED, $result);

        $this->runWithFirmContext($firm, function () use ($attempt, $provider) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Captured, $fresh->state);

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(), 'ONE financial posting.');

            $this->assertSame(1, DB::table('integration_webhook_routing_index')
                ->where('integration_provider_id', $provider->id)
                ->where('provider_resource_id', $fresh->provider_reference)
                ->count(), 'ONE ownership/mapping relationship.');
        });
    }

    /** FV-A3-030 — the same provider event delivered twice deduplicates in the database. */
    public function test_fv_a3_030_duplicate_event_is_deduplicated(): void
    {
        [$firm, $attempt, $provider, $connection] = $this->capturedViaProvider();

        $event = $this->successEvent($attempt, (int) $provider->id, $provider->code, 'evt-dup-1');

        $first = $this->ingestion()->ingest($event);
        $second = $this->ingestion()->ingest($event);

        $this->assertSame(ProviderEventIngestionService::PROCESSED, $first);
        $this->assertSame(ProviderEventIngestionService::DUPLICATE, $second);

        $this->runWithFirmContext($firm, function () use ($attempt, $provider, $connection) {
            // One canonical event row — the unique constraint arbitrated.
            $this->assertSame(1, DB::table('integration_inbound_webhook_events')
                ->where('firm_integration_id', $connection->id)
                ->where('provider_key', $provider->code)
                ->where('provider_event_id', 'evt-dup-1')
                ->count());

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(), 'ONE canonical economic effect.');
        });
    }

    /**
     * FV-A3-031 / FV-A3-032 — an event whose internal dependency does
     * not exist yet is DEFERRED (never failed, never guessed) and
     * processes exactly once after the dependency appears.
     */
    public function test_fv_a3_031_032_out_of_order_event_defers_then_succeeds(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        // Ownership exists (the locator knows the resource), but the
        // attempt that will carry this provider reference does not yet.
        app(ProviderResourceOwnershipService::class)->establishOwnership(
            (int) $firm->id, (int) $connection->id, (int) $provider->id, 'payment', 'fpr-early-1',
        );

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');

        $early = new FakeProviderEvent(
            integrationProviderId: (int) $provider->id,
            providerKey: $provider->code,
            eventId: 'evt-early-1',
            resourceType: 'payment',
            resourceReference: 'fpr-early-1',
            outcome: ProviderOutcome::Succeeded,
            amountCents: 10_000,
            environment: 'sandbox',
        );

        $this->assertSame(ProviderEventIngestionService::DEFERRED, $this->ingestion()->ingest($early));

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $this->assertSame(PaymentAttemptState::Created, PaymentAttempt::query()->findOrFail($attempt->id)->state,
                'A deferred event must not touch the attempt.');
            $this->assertSame(0, DB::table('accounting_journal_entries')->count());
        });

        // Dependency appears: the attempt is submitted and carries the
        // provider reference the event names.
        $this->runWithFirmContext($firm, function () use ($attempt) {
            app(PaymentAttemptService::class)->transition(
                PaymentAttempt::query()->findOrFail($attempt->id),
                PaymentAttemptState::Submitted,
                providerReference: 'fpr-early-1',
            );
        });

        // The SAME event now processes successfully — exactly once.
        $this->assertSame(ProviderEventIngestionService::PROCESSED, $this->ingestion()->ingest($early));

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $this->assertSame(PaymentAttemptState::Captured, PaymentAttempt::query()->findOrFail($attempt->id)->state);
            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(), 'Deferred-then-processed applies ONCE.');
        });

        // And re-delivering it afterwards is a duplicate, not a re-apply.
        $this->assertSame(ProviderEventIngestionService::DUPLICATE, $this->ingestion()->ingest($early));
    }

    /**
     * FV-A3-033 — an authenticated event whose resource has NO ownership
     * locator entry stays restricted: no canonical event, no guessed
     * firm, no financial mutation of any kind.
     */
    public function test_fv_a3_033_unmapped_event_cannot_enter_tenant_financial_domain(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $unmapped = new FakeProviderEvent(
            integrationProviderId: (int) $provider->id,
            providerKey: $provider->code,
            eventId: 'evt-foreign-1',
            resourceType: 'payment',
            resourceReference: 'fpr-nobody-owns-this',
            outcome: ProviderOutcome::Succeeded,
            amountCents: 123_456,
            environment: 'sandbox',
        );

        $this->assertSame(ProviderEventIngestionService::UNRESOLVED, $this->ingestion()->ingest($unmapped));

        $this->runWithFirmContext($firm, function () {
            $this->assertSame(0, DB::table('integration_inbound_webhook_events')->count(),
                'No canonical event with a guessed firm may exist.');
            $this->assertSame(0, DB::table('accounting_journal_entries')->count());
            $this->assertSame(0, PaymentAttempt::query()->whereNotNull('resolved_at')->count());
        });

        // Repeating it never wears the restriction down.
        $this->assertSame(ProviderEventIngestionService::UNRESOLVED, $this->ingestion()->ingest($unmapped));
    }

    /** FV-A3-034 — an event presented through the WRONG provider connection fails closed. */
    public function test_fv_a3_034_wrong_provider_connection_fails_closed(): void
    {
        [$firm, $attempt, $provider, $connection] = $this->capturedViaProvider();

        // A second, different connection presents the event.
        $otherConnection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
        ]));

        $journalBefore = $this->runWithFirmContext($firm, fn () => DB::table('accounting_journal_entries')->count());

        $result = $this->ingestion()->ingest($this->successEvent(
            $attempt, (int) $provider->id, $provider->code, 'evt-wrong-conn-1',
            ['presented' => (int) $otherConnection->id, 'outcome' => ProviderOutcome::Failed],
        ));

        $this->assertSame(ProviderEventIngestionService::CONNECTION_MISMATCH, $result);

        $this->runWithFirmContext($firm, function () use ($attempt, $journalBefore) {
            $this->assertSame(PaymentAttemptState::Captured, PaymentAttempt::query()->findOrFail($attempt->id)->state,
                'A mismatched event must mutate nothing.');
            $this->assertSame($journalBefore, DB::table('accounting_journal_entries')->count());
        });
    }

    /** FV-A3-035 — an event with a mismatched environment context fails closed. */
    public function test_fv_a3_035_wrong_environment_fails_closed(): void
    {
        [$firm, $attempt, $provider, $connection] = $this->capturedViaProvider();

        $journalBefore = $this->runWithFirmContext($firm, fn () => DB::table('accounting_journal_entries')->count());

        $result = $this->ingestion()->ingest($this->successEvent(
            $attempt, (int) $provider->id, $provider->code, 'evt-wrong-env-1',
            ['environment' => 'live', 'outcome' => ProviderOutcome::Failed],
        ));

        $this->assertSame(ProviderEventIngestionService::ENVIRONMENT_MISMATCH, $result);

        $this->runWithFirmContext($firm, function () use ($attempt, $journalBefore) {
            $this->assertSame(PaymentAttemptState::Captured, PaymentAttempt::query()->findOrFail($attempt->id)->state);
            $this->assertSame($journalBefore, DB::table('accounting_journal_entries')->count());
        });
    }

    /**
     * FV-A3-036 — the ownership resolver remains THE authority: one
     * external resource resolves to exactly one firm/provider account,
     * and no fake-provider-specific ownership table exists.
     */
    public function test_fv_a3_036_ownership_resolver_remains_authoritative(): void
    {
        [$firm, $attempt, $provider, $connection] = $this->capturedViaProvider();

        $owner = app(ProviderResourceOwnershipService::class)->resolveOwner(
            (int) $provider->id, 'payment', (string) $attempt->provider_reference,
        );

        $this->assertNotNull($owner);
        $this->assertSame((int) $firm->id, $owner->firmId);
        $this->assertSame((int) $connection->id, $owner->firmIntegrationId);

        // Exactly one ownership row, in THE one authority table.
        $this->assertSame(1, DB::table('integration_webhook_routing_index')
            ->where('integration_provider_id', $provider->id)
            ->where('provider_resource_type', 'payment')
            ->where('provider_resource_id', $attempt->provider_reference)
            ->count());

        // No fake-provider-specific ownership table was created (§27).
        foreach (['fake_provider_resources', 'fake_payment_resources', 'pay_provider_resources'] as $forbidden) {
            $this->assertFalse(Schema::hasTable($forbidden));
        }
    }
}
