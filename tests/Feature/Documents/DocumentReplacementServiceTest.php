<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionStatus;
use App\Models\Document;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentReplacementServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentReplacementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentReplacementService(app(DomainEventRecorderService::class));
    }

    public function test_capture_current_as_version_snapshots_the_existing_pointer(): void
    {
        $document = Document::factory()->create([
            'storage_path' => 'documents/v1.pdf',
            'file_hash' => 'hash-v1',
        ]);

        $version = $this->service->captureCurrentAsVersion($document);

        $this->assertSame(1, $version->version_number);
        $this->assertSame(DocumentVersionStatus::Current, $version->status);
        $this->assertSame('documents/v1.pdf', $version->storage_path);
    }

    public function test_a_second_capture_supersedes_the_first_version(): void
    {
        $document = Document::factory()->create(['storage_path' => 'documents/v1.pdf']);

        $first = $this->service->captureCurrentAsVersion($document);
        $document->update(['storage_path' => 'documents/v2.pdf']);
        $second = $this->service->captureCurrentAsVersion($document);

        $this->assertSame(DocumentVersionStatus::Superseded, $first->fresh()->status);
        $this->assertSame(DocumentVersionStatus::Current, $second->status);
        $this->assertSame(2, $second->version_number);
    }

    public function test_replace_with_marks_the_original_replaced_and_never_deletes_it(): void
    {
        $original = Document::factory()->create([
            'status' => DocumentStatus::NeedsReplacement,
            'storage_path' => 'documents/original.pdf',
        ]);

        $replacement = $this->service->replaceWith(
            $original,
            'local',
            'documents/replacement.pdf',
            'replacement.pdf',
            'application/pdf',
            4096,
            hash('sha256', 'replacement'),
        );

        $this->runWithFirmContext($original->firm_id, function () use ($original) {
            $this->assertSame(DocumentStatus::Replaced, $original->fresh()->status);
            $this->assertNotNull(Document::query()->find($original->id)); // never deleted
        });
        $this->assertSame($original->id, $replacement->replaces_document_id);
        $this->assertSame(DocumentStatus::Uploaded, $replacement->status);
        $this->assertSame(DocumentScanStatus::Pending, $replacement->scan_status);
    }

    public function test_replace_with_moves_a_needs_replacement_request_item_back_to_submitted(): void
    {
        $firm = Firm::factory()->create();
        $item = DocumentRequestItem::factory()->create(['status' => DocumentRequestItemStatus::NeedsReplacement]);
        $original = Document::factory()->create([
            'firm_id' => $firm->id,
            'document_request_item_id' => $item->id,
        ]);

        $this->service->replaceWith(
            $original,
            'local',
            'documents/replacement.pdf',
            'replacement.pdf',
            'application/pdf',
            4096,
            hash('sha256', 'replacement'),
        );

        $this->assertSame(DocumentRequestItemStatus::Submitted, $item->fresh()->status);
    }
}
