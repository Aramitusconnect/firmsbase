<?php

namespace Tests\Feature\Signature;

use App\Services\AcknowledgmentSignatureFoundationService;
use App\ValueObjects\AcknowledgmentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms the Phase 6 signature-request FOUNDATION stays exactly a
 * value object + service, with no persisted table and no signer
 * workflow (approved decision — full e-signature is Phase 11's job).
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

    public function test_no_signature_requests_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('signature_requests'));
    }
}
