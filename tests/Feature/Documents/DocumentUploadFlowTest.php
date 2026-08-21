<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\DocumentsRelationManager;
use App\Jobs\ScanDocumentJob;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DocumentUploadFlowTest — Mission 3 (Document Center Completion),
 * section 3.1. Proves the new `upload` header action on
 * DocumentsRelationManager is a real, working entry point: it moves the
 * uploaded file to a durable matter-scoped path, creates a real
 * `Document` row via `DocumentSecurityService::upload()` (scan_status
 * Pending — never bypassing the scan gate), and dispatches
 * `ScanDocumentJob` — mirroring the same assertion shape
 * MarketplaceIntakeDocumentServiceTest already uses for the identical
 * dispatch call.
 */
final class DocumentUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_the_upload_action_creates_a_pending_document_and_dispatches_a_scan_job(): void
    {
        Storage::fake('local');
        Bus::fake([ScanDocumentJob::class]);

        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($matter, $firm, $firmUser): void {
            $test = Livewire::test(DocumentsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);

            $test->callAction(TestAction::make('upload')->table(), data: ['file' => UploadedFile::fake()->createWithContent('evidence.pdf', str_repeat('A', 2048))]);
            $test->assertHasNoActionErrors();

            $document = Document::query()->where('matter_id', $matter->id)->latest('id')->first();

            $this->assertNotNull($document, 'The upload action must create a real Document row.');
            $this->assertSame($firm->id, $document->firm_id);
            $this->assertSame(DocumentStatus::Uploaded, $document->status);
            $this->assertSame(DocumentScanStatus::Pending, $document->scan_status, 'A freshly uploaded document must never skip the scan gate.');
            $this->assertSame($firmUser->user_id, $document->uploaded_by);
            $this->assertStringStartsWith("documents/{$firm->id}/{$matter->id}/", $document->storage_path);
            $this->assertTrue(Storage::disk('local')->exists($document->storage_path), 'The uploaded file must be moved to its final durable path.');

            Bus::assertDispatched(ScanDocumentJob::class, fn (ScanDocumentJob $job): bool => $job->documentId === $document->id && $job->firmId === $firm->id);
        });
    }

    public function test_a_dangerous_file_extension_is_rejected_and_creates_no_document_row(): void
    {
        Storage::fake('local');
        Bus::fake([ScanDocumentJob::class]);

        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($matter): void {
            $test = Livewire::test(DocumentsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);

            $test->callAction(TestAction::make('upload')->table(), data: ['file' => UploadedFile::fake()->createWithContent('malware.exe', str_repeat('A', 1024))]);

            $this->assertSame(0, Document::query()->where('matter_id', $matter->id)->count());
            Bus::assertNotDispatched(ScanDocumentJob::class);
        });
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
