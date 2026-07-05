<?php

namespace Tests\Feature\Pdf;

use App\Enums\PdfViewEventAction;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\PdfViewEventService;
use App\ValueObjects\PdfAccessDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfViewEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdfViewEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PdfViewEventService();
    }

    public function test_record_opened_persists_expected_fields(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $event = $this->service->recordOpened(
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            '203.0.113.9', 'Mozilla/5.0',
        );

        $this->assertSame(PdfViewEventAction::Opened, $event->action);
        $this->assertSame('203.0.113.9', $event->ip_address);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($document->id, $event->document_id);
    }

    public function test_download_requested_and_download_allowed_are_two_separate_rows(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->service->recordDownloadRequested(
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            '203.0.113.9', 'Mozilla/5.0',
        );

        $this->service->recordDownloadDecision(
            PdfAccessDecision::allow('firm user has access'),
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            '203.0.113.9', 'Mozilla/5.0',
        );

        $this->assertDatabaseHas('pdf_view_events', ['action' => PdfViewEventAction::DownloadRequested->value]);
        $this->assertDatabaseHas('pdf_view_events', ['action' => PdfViewEventAction::DownloadAllowed->value]);
        $this->assertSame(2, \App\Models\PdfViewEvent::query()->count());
    }

    public function test_download_denied_is_logged_when_decision_is_a_denial(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->service->recordDownloadDecision(
            PdfAccessDecision::deny('not usable'),
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            '203.0.113.9', 'Mozilla/5.0',
        );

        $this->assertDatabaseHas('pdf_view_events', ['action' => PdfViewEventAction::DownloadDenied->value]);
    }
}
