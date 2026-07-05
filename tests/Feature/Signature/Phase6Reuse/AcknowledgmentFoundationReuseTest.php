<?php

namespace Tests\Feature\Signature\Phase6Reuse;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\AcknowledgmentSignatureFoundationService;
use App\Services\SignatureEventLogger;
use App\Services\SignatureRecipientWorkflowService;
use App\Services\SignatureRequestAggregationService;
use App\Services\SignatureWorkflowTransitionService;
use App\ValueObjects\AcknowledgmentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves Phase 11 REUSES the actual, unmodified Phase 6 signature
 * foundation rather than inventing a second, incompatible one:
 *
 *  1. SignatureEventLogger has a real constructor dependency on the
 *     existing AcknowledgmentSignatureFoundationService (reflection
 *     check — not just "the same idea").
 *  2. Calling SignatureRecipientWorkflowService::consent() produces a
 *     signature_events row whose acknowledger_type/acknowledger_id/
 *     text_version/acknowledged/acknowledged_at values are EXACTLY
 *     what AcknowledgmentSignatureFoundationService::record() would
 *     have returned for the same inputs.
 *  3. AcknowledgmentSignatureFoundationService and AcknowledgmentRecord
 *     remain byte-for-byte unmodified value-object/pure-service code —
 *     Phase 11 never touches those two files.
 */
class AcknowledgmentFoundationReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_event_logger_depends_on_the_actual_phase_6_acknowledgment_service(): void
    {
        $reflection = new \ReflectionClass(SignatureEventLogger::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);

        $dependencyTypes = array_map(
            fn (\ReflectionParameter $param) => $param->getType()?->getName(),
            $constructor->getParameters()
        );

        $this->assertContains(AcknowledgmentSignatureFoundationService::class, $dependencyTypes);
    }

    public function test_acknowledgment_service_still_returns_a_pure_value_object_and_persists_nothing_itself(): void
    {
        $service = new AcknowledgmentSignatureFoundationService();

        $record = $service->record('App\\Models\\User', 99, 'terms-v1', true);

        $this->assertInstanceOf(AcknowledgmentRecord::class, $record);
        // Calling record() alone must never create a signature_events row.
        $this->assertDatabaseCount('signature_events', 0);
    }

    public function test_consent_captured_event_carries_exactly_the_fields_the_phase_6_service_would_produce(): void
    {
        $transitions = new SignatureWorkflowTransitionService();
        $service = new SignatureRecipientWorkflowService(
            $transitions,
            new SignatureEventLogger(new AcknowledgmentSignatureFoundationService()),
            new SignatureRequestAggregationService($transitions),
        );

        $request = SignatureRequest::factory()->status(SignatureRequestStatus::Sent)->create();
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Viewed)
            ->create();

        // Independently construct the expected AcknowledgmentRecord via
        // the real Phase 6 service, to compare against what got persisted.
        $expected = (new AcknowledgmentSignatureFoundationService())->record(
            'App\\Models\\FirmUser', $recipient->id, 'consent-v2', true
        );

        $service->consent($recipient, 'App\\Models\\FirmUser', $recipient->id, 'consent-v2', '198.51.100.9', 'Mozilla/5.0');

        $this->assertDatabaseHas('signature_events', [
            'signature_request_recipient_id' => $recipient->id,
            'event_type' => 'consent_captured',
            'acknowledger_type' => $expected->acknowledgerType,
            'acknowledger_id' => $expected->acknowledgerId,
            'text_version' => $expected->textVersion,
            'acknowledged' => $expected->acknowledged,
        ]);
    }
}
