<?php

namespace Tests\Feature\Pdf;

use App\Enums\EntitlementSource;
use App\Enums\PdfAnnotationType;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EntitlementService;
use App\Services\PdfAnnotationService;
use App\Services\PdfViewEventService;
use App\Services\SignatureAndPdfAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required correctness test: "annotations if enabled" — disabled
 * unless a firm's e_signature entitlement settings explicitly set
 * annotations_enabled to true. No new module_catalog row is used.
 */
class PdfAnnotationDisabledByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private PdfAnnotationService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new PdfAnnotationService(
            new SignatureAndPdfAccessPolicyService($this->entitlements),
            new PdfViewEventService(),
        );
    }

    public function test_annotation_is_blocked_by_default(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->annotate(
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            PdfAnnotationType::Highlight, 1, 'important clause',
            '203.0.113.9', 'Mozilla/5.0',
        );
    }

    public function test_annotation_succeeds_once_explicitly_enabled(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->entitlements->setForSource(
            $firm, 'e_signature', EntitlementSource::AdminOverride, true, ['annotations_enabled' => true]
        );

        $event = $this->service->annotate(
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            PdfAnnotationType::Highlight, 1, 'important clause',
            '203.0.113.9', 'Mozilla/5.0',
        );

        $this->assertSame(\App\Enums\PdfViewEventAction::AnnotationAdded, $event->action);
        $this->assertSame(PdfAnnotationType::Highlight, $event->annotation_type);
    }

    public function test_enabling_e_signature_alone_without_the_setting_still_blocks_annotation(): void
    {
        $firm = Firm::factory()->create();
        $viewer = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->entitlements->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true, []);

        $this->expectException(\RuntimeException::class);
        $this->service->annotate(
            $firm, PdfViewerViewerType::FirmUser, $viewer, null,
            SignatureSourceDocumentType::Document, $document, null,
            PdfAnnotationType::Note, 1, null,
            '203.0.113.9', 'Mozilla/5.0',
        );
    }
}
