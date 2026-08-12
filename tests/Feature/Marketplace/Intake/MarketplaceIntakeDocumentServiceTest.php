<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\MarketplaceIntakeEventType;
use App\Jobs\ScanDocumentJob;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeDocumentService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\DocumentSecurityService;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 7 —
 * MarketplaceIntakeDocumentService. Proves an anonymous intake upload
 * reuses the existing quarantine pipeline (DocumentUploadPolicyService/
 * DocumentSecurityService/ScanDocumentJob) wholesale rather than a
 * parallel one, and that nothing infected/pending ever reaches a
 * Firm-facing "usable" query.
 */
class MarketplaceIntakeDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MarketplaceIntakeDocumentService
    {
        return app(MarketplaceIntakeDocumentService::class);
    }

    /**
     * @return array{0: Firm, 1: MarketplaceIntake}
     */
    private function setUpIntake(): array
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);

        return [$firm, $intake];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_upload_creates_a_pending_document_linked_to_the_intake(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

        $document = $this->service()->upload($intake, $file, '203.0.113.5');

        $this->assertSame($intake->id, $document->marketplace_intake_id);
        $this->assertSame($firm->id, $document->firm_id);
        $this->assertNull($document->matter_id);
        $this->assertNull($document->client_id);
        $this->assertNull($document->uploaded_by);
        $this->assertSame(DocumentStatus::Uploaded, $document->status);
        $this->assertSame(DocumentScanStatus::Pending, $document->scan_status);
        Storage::disk('local')->assertExists($document->storage_path);
    }

    public function test_upload_never_uses_the_visitors_filename_as_the_storage_path(): void
    {
        [, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('../../etc/passwd.pdf', 10, 'application/pdf');

        $document = $this->service()->upload($intake, $file);

        $this->assertStringNotContainsString('../', $document->storage_path);
        $this->assertStringStartsWith('marketplace-intake-uploads/', $document->storage_path);
    }

    public function test_upload_rejects_a_disallowed_extension(): void
    {
        [, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('malware.exe', 10);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->upload($intake, $file);
    }

    public function test_upload_rejects_an_oversized_file(): void
    {
        [, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('huge.pdf', 26_000, 'application/pdf');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->upload($intake, $file);
    }

    public function test_upload_rejects_when_the_intake_is_terminal(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        $abandoned = app(MarketplaceIntakeService::class)->abandonExpired($firm, $intake);
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $this->expectException(\RuntimeException::class);

        $this->service()->upload($abandoned, $file);
    }

    public function test_upload_dispatches_scan_document_job(): void
    {
        Bus::fake();
        [$firm, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $document = $this->service()->upload($intake, $file);

        Bus::assertDispatched(ScanDocumentJob::class, fn (ScanDocumentJob $job) => $job->documentId === $document->id && $job->firmId === $firm->id);
    }

    public function test_upload_records_a_document_uploaded_intake_event(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $document = $this->service()->upload($intake, $file);

        $event = $this->runWithFirmContext($firm, fn () => $intake->events()->latest('id')->first());
        $this->assertSame(MarketplaceIntakeEventType::DocumentUploaded, $event->event_type);
        $this->assertSame($document->id, $event->metadata['document_id']);
    }

    public function test_a_synchronously_run_infected_scan_marks_the_document_unusable(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        // FakeVirusScanner flags any storage path containing "infected"
        // — the original filename becomes part of the storage path
        // suffix, so this filename deterministically triggers it.
        $file = UploadedFile::fake()->create('infected-invoice.pdf', 10, 'application/pdf');

        $document = $this->service()->upload($intake, $file);

        $this->runWithFirmContext($firm, function () use ($document, $firm) {
            (new ScanDocumentJob($document->id, $firm->id))->handle(
                app(VirusScanner::class),
                app(DocumentSecurityService::class),
            );
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $document->fresh());
        $this->assertSame(DocumentScanStatus::Infected, $fresh->scan_status);
        $this->assertSame(DocumentStatus::Rejected, $fresh->status);
        $this->assertFalse($fresh->isUsable());
    }

    public function test_visitor_summary_never_exposes_the_rejected_reason_or_scan_detail(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        $file = UploadedFile::fake()->create('infected-invoice.pdf', 10, 'application/pdf');
        $document = $this->service()->upload($intake, $file);
        $this->runWithFirmContext($firm, function () use ($document, $firm) {
            (new ScanDocumentJob($document->id, $firm->id))->handle(
                app(VirusScanner::class),
                app(DocumentSecurityService::class),
            );
        });

        $summary = $this->service()->visitorSummary($firm, $intake);

        $this->assertCount(1, $summary);
        $this->assertFalse($summary[0]['accepted']);
        $this->assertArrayNotHasKey('rejected_reason', $summary[0]);
        $this->assertArrayNotHasKey('scan_result_detail', $summary[0]);
    }

    public function test_usable_documents_for_firm_review_excludes_pending_and_infected(): void
    {
        // Bus::fake() prevents the upload()-dispatched ScanDocumentJob
        // from actually running (QUEUE_CONNECTION=sync in the test
        // environment would otherwise scan it inline, immediately
        // clearing this document) — needed here specifically to
        // observe a genuinely still-Pending row.
        Bus::fake();
        [$firm, $intake] = $this->setUpIntake();

        $pending = $this->service()->upload($intake, UploadedFile::fake()->create('pending.pdf', 10, 'application/pdf'));
        $infected = $this->service()->upload($intake, UploadedFile::fake()->create('infected-bad.pdf', 10, 'application/pdf'));
        $this->runWithFirmContext($firm, function () use ($infected, $firm) {
            (new ScanDocumentJob($infected->id, $firm->id))->handle(
                app(VirusScanner::class),
                app(DocumentSecurityService::class),
            );
        });

        $usable = $this->service()->usableDocumentsForFirmReview($firm, $intake);

        $this->assertCount(0, $usable);
        $this->assertNotContains($pending->id, $usable->pluck('id'));
        $this->assertNotContains($infected->id, $usable->pluck('id'));
    }

    public function test_usable_documents_for_firm_review_includes_a_clean_document(): void
    {
        [$firm, $intake] = $this->setUpIntake();
        $document = $this->service()->upload($intake, UploadedFile::fake()->create('clean.pdf', 10, 'application/pdf'));
        $this->runWithFirmContext($firm, function () use ($document, $firm) {
            (new ScanDocumentJob($document->id, $firm->id))->handle(
                app(VirusScanner::class),
                app(DocumentSecurityService::class),
            );
        });

        $usable = $this->service()->usableDocumentsForFirmReview($firm, $intake);

        $this->assertCount(1, $usable);
        $this->assertSame($document->id, $usable->first()->id);
    }

    public function test_upload_never_creates_a_matter_or_client(): void
    {
        [$firm, $intake] = $this->setUpIntake();

        $this->service()->upload($intake, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $this->service()->upload($intake, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));

        $matterCount = $this->runWithFirmContext($firm, fn () => Matter::query()->count());
        $clientCount = $this->runWithFirmContext($firm, fn () => Client::query()->count());
        $this->assertSame(0, $matterCount);
        $this->assertSame(0, $clientCount);
    }

    public function test_usable_documents_for_firm_review_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        [, $intakeA] = $this->setUpIntake();
        $firmB = Firm::factory()->create();
        $directoryFirmB = DirectoryFirm::factory()->member()->create(['firm_id' => $firmB->id, 'accepting_inquiries' => true]);
        app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirmB);

        $this->service()->upload($intakeA, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->expectException(\RuntimeException::class);

        $this->service()->usableDocumentsForFirmReview($firmB, $intakeA);
    }
}
