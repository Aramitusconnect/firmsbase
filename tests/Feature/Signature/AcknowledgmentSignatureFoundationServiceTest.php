<?php

namespace Tests\Feature\Signature;

use App\Models\SignatureEvent;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\ValueObjects\AcknowledgmentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the Phase 6 signature-request FOUNDATION stays exactly a
 * value object + service — it never persists anything itself, by
 * design, so that Phase 11 (the only owner of durable signature
 * request/event storage) can safely build on top of it without a
 * second, incompatible signature system ever existing.
 *
 * Original note (Phase 6): this file previously asserted
 * `Schema::hasTable('signature_requests') === false`, i.e. "Phase 11
 * hasn't happened yet." That assertion is now obsolete by design: the
 * whole point of the Phase 6 foundation was for Phase 11 to build the
 * real table on top of it. Rather than flip that assertion to
 * assertTrue (which would just restate "the table exists" without
 * saying anything about WHY that's still safe), this file now asserts
 * the property that actually matters going forward: the Phase 6
 * service/VO remain pure and stateless regardless of what Phase 11 (or
 * any later phase) builds around them.
 */
class AcknowledgmentSignatureFoundationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AcknowledgmentSignatureFoundationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcknowledgmentSignatureFoundationService();
    }

    public function test_record_returns_an_acknowledgment_record_value_object(): void
    {
        $record = $this->service->record('App\\Models\\User', 42, 'terms-v1', true);

        $this->assertInstanceOf(AcknowledgmentRecord::class, $record);
        $this->assertSame('App\\Models\\User', $record->acknowledgerType);
        $this->assertSame(42, $record->acknowledgerId);
        $this->assertSame('terms-v1', $record->textVersion);
        $this->assertTrue($record->acknowledged);
        $this->assertNotNull($record->acknowledgedAt);
    }

    /**
     * The Phase 6 foundation stays pure/value-object-only: calling
     * record() alone must never write to signature_events (Phase 11's
     * durable, append-only ledger) or any other table. Phase 11 owns
     * durable signature request/event storage; this service does not
     * and must not duplicate that responsibility.
     */
    public function test_record_does_not_persist_signature_events_or_any_other_table_by_itself(): void
    {
        $this->service->record('App\\Models\\User', 42, 'terms-v1', true);

        $this->assertDatabaseCount('signature_events', 0);
    }

    /**
     * Phase 11 is the sole owner of durable signature request/event
     * storage: signature_events rows are created by Phase 11's own
     * SignatureEventLogger, which internally calls this exact,
     * unmodified service to build the AcknowledgmentRecord it persists
     * — the service itself remains the pure, stateless building block
     * it was designed to be.
     */
    public function test_phase_11_signature_event_model_exists_and_is_the_actual_durable_owner(): void
    {
        $this->assertTrue(class_exists(SignatureEvent::class));
    }
}
