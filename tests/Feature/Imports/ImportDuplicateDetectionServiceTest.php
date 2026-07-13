<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportEntityType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\Party;
use App\Services\ImportAuditService;
use App\Services\ImportDuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportDuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportDuplicateDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportDuplicateDetectionService(new ImportAuditService());
    }

    public function test_client_duplicate_detected_by_email(): void
    {
        $firm = Firm::factory()->create();
        $existing = Client::factory()->create(['firm_id' => $firm->id, 'email' => 'dup@example.test']);
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Client)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['email' => 'dup@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame($existing->id, $result->matchedId);
        $this->assertDatabaseHas('import_audit_events', ['import_batch_id' => $batch->id, 'event_type' => 'duplicate_detected']);
    }

    /**
     * Section 39A-3L Phase B5 proof: detectContact()/detectParty() are
     * each now wrapped in runWithFirmContext() (preparing for a future
     * FORCE ROW LEVEL SECURITY activation on contacts/parties, not yet
     * active in this batch). No existing test in this file exercised
     * Contact or Party duplicate detection at all before this batch —
     * only Client, Invoice, ConflictRecord, and Template. Assert the
     * wrap did not break normal detection behavior for either type.
     */
    public function test_contact_duplicate_detected_by_email(): void
    {
        $firm = Firm::factory()->create();
        $existing = Contact::factory()->create(['firm_id' => $firm->id, 'email' => 'contact-dup@example.test']);
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Contact)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['email' => 'contact-dup@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame($existing->id, $result->matchedId);
        $this->assertSame(Contact::class, $result->matchedType);
        $this->assertDatabaseHas('import_audit_events', ['import_batch_id' => $batch->id, 'event_type' => 'duplicate_detected']);
    }

    public function test_contact_no_match_when_nothing_matches(): void
    {
        $firm = Firm::factory()->create();
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Contact)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['email' => 'contact-unique@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertFalse($result->isDuplicate);
    }

    public function test_party_duplicate_detected_by_name_and_email(): void
    {
        $firm = Firm::factory()->create();
        $existing = Party::factory()->create(['firm_id' => $firm->id, 'name' => 'Repeat Party', 'email' => 'party-dup@example.test']);
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Party)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['name' => 'Repeat Party', 'email' => 'party-dup@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame($existing->id, $result->matchedId);
        $this->assertSame(Party::class, $result->matchedType);
    }

    public function test_party_no_match_when_nothing_matches(): void
    {
        $firm = Firm::factory()->create();
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Party)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['name' => 'Nobody Here', 'email' => 'party-unique@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertFalse($result->isDuplicate);
    }

    public function test_no_match_when_nothing_matches(): void
    {
        $firm = Firm::factory()->create();
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Client)->create();
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['email' => 'unique@example.test'], 'status' => 'validated']);

        $result = $this->service->detect($row);

        $this->assertFalse($result->isDuplicate);
    }

    public function test_invoice_duplicate_detection_uses_import_metadata_only_not_the_invoices_table(): void
    {
        $firm = Firm::factory()->create();
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Invoice)->create();

        $rowA = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['external_reference' => 'INV-100'], 'status' => 'validated']);
        $rowB = $batch->rows()->create(['row_number' => 2, 'raw_data' => ['external_reference' => 'INV-100'], 'status' => 'validated']);

        $resultA = $this->service->detect($rowA);
        $resultB = $this->service->detect($rowB);

        $this->assertFalse($resultA->isDuplicate);
        $this->assertTrue($resultB->isDuplicate);
        $this->assertSame(\App\Models\ImportRow::class, $resultB->matchedType);
    }

    public function test_conflict_record_and_template_entity_types_always_return_no_match(): void
    {
        $firm = Firm::factory()->create();

        $conflictBatch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::ConflictRecord)->create();
        $conflictRow = $conflictBatch->rows()->create(['row_number' => 1, 'raw_data' => ['name' => 'anything'], 'status' => 'validated']);

        $templateBatch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Template)->create();
        $templateRow = $templateBatch->rows()->create(['row_number' => 1, 'raw_data' => ['name' => 'anything'], 'status' => 'validated']);

        $this->assertFalse($this->service->detect($conflictRow)->isDuplicate);
        $this->assertFalse($this->service->detect($templateRow)->isDuplicate);
    }
}
