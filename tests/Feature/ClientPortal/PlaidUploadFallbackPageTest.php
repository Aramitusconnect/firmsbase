<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Filament\ClientPortal\Pages\PlaidUploadFallbackPage;
use App\Jobs\ScanDocumentJob;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * PlaidUploadFallbackPageTest — non-payment completion program
 * (staging deployment mission). Previously this Filament page's
 * handleUpload() had zero test coverage. Proves:
 *   1. the real upload flow works end to end (Document row created,
 *      ScanDocumentJob dispatched) — mirroring the equivalent proof
 *      DocumentUploadFlowTest already establishes for the Firm-panel
 *      upload path;
 *   2. handleUpload() writes to the app's CONFIGURED default disk
 *      (config('filesystems.default')), never a hardcoded 'local'
 *      literal — the exact defect this mission's document-storage fix
 *      corrected, since a hardcoded 'local' both fails to find
 *      Livewire's own temp upload (which already resolves to
 *      config('filesystems.default')) whenever FILESYSTEM_DISK is
 *      anything else, and would otherwise pin the final stored copy to
 *      the ECS task's own non-durable filesystem.
 */
final class PlaidUploadFallbackPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
    }

    public function test_handle_upload_creates_a_pending_document_and_dispatches_a_scan_job(): void
    {
        Storage::fake('local');
        Bus::fake([ScanDocumentJob::class]);

        ['firm' => $firm, 'matter' => $matter, 'portalUser' => $portalUser] = $this->makeMatterWithGrant();

        Auth::guard('client')->login($portalUser);

        $this->withinClientPortalRequest($firm, function () use ($firm, $matter): void {
            $test = Livewire::test(PlaidUploadFallbackPage::class, ['matter' => (string) $matter->id]);
            $test->set('data.file', UploadedFile::fake()->createWithContent('statement.pdf', str_repeat('A', 2048)));
            $test->call('handleUpload');

            $document = Document::query()->where('matter_id', $matter->id)->latest('id')->first();

            $this->assertNotNull($document, 'The upload action must create a real Document row.');
            $this->assertSame($firm->id, $document->firm_id);
            $this->assertSame(DocumentStatus::Uploaded, $document->status);
            $this->assertSame(DocumentScanStatus::Pending, $document->scan_status, 'A freshly uploaded document must never skip the scan gate.');
            $this->assertStringStartsWith("client-portal-uploads/{$firm->id}/{$matter->id}/", $document->storage_path);
            $this->assertTrue(Storage::disk('local')->exists($document->storage_path), 'The uploaded file must be moved to its final durable path.');

            Bus::assertDispatched(ScanDocumentJob::class, fn (ScanDocumentJob $job): bool => $job->documentId === $document->id && $job->firmId === $firm->id);
        });
    }

    public function test_handle_upload_uses_the_configured_default_disk_not_a_hardcoded_local_literal(): void
    {
        Config::set('filesystems.default', 's3');
        Storage::fake('s3');
        Storage::fake('local');
        Bus::fake([ScanDocumentJob::class]);

        ['firm' => $firm, 'matter' => $matter, 'portalUser' => $portalUser] = $this->makeMatterWithGrant();

        Auth::guard('client')->login($portalUser);

        $this->withinClientPortalRequest($firm, function () use ($matter): void {
            $test = Livewire::test(PlaidUploadFallbackPage::class, ['matter' => (string) $matter->id]);
            $test->set('data.file', UploadedFile::fake()->createWithContent('statement.pdf', str_repeat('A', 2048)));
            $test->call('handleUpload');

            $document = Document::query()->where('matter_id', $matter->id)->latest('id')->first();

            $this->assertNotNull($document);
            $this->assertSame('s3', $document->storage_disk, 'The uploaded document must be recorded on the CONFIGURED default disk, not a hardcoded local literal.');
            Storage::disk('s3')->assertExists($document->storage_path);
            Storage::disk('local')->assertMissing($document->storage_path);
        });
    }

    public function test_handle_upload_denies_a_portal_user_with_no_matter_grant(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($client)->create());
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => 'ungranted-'.uniqid().'@example.test',
            'password' => 'irrelevant-hashed-value',
            'is_active' => true,
        ]));

        Auth::guard('client')->login($portalUser);

        $this->withinClientPortalRequest($firm, function () use ($matter): void {
            // Mirrors PlaidConsentPageRevokeConnectionTest's established
            // workaround: mount()'s AccessDeniedHttpException does not
            // propagate cleanly through Livewire::test()'s full
            // request-cycle simulation (the correct fail-closed page
            // simply never becomes mountable) — invoking the page
            // object's mount() directly proves the same guard
            // independently of the surrounding Livewire harness.
            $page = new PlaidUploadFallbackPage;
            $page->matter = (string) $matter->id;

            $this->expectException(AccessDeniedHttpException::class);

            $page->mount();
        });

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * @return array{firm: Firm, client: Client, matter: Matter, portalUser: ClientPortalUser}
     */
    private function makeMatterWithGrant(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm): array {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $portalUser = ClientPortalUser::query()->create([
                'client_id' => $client->id,
                'email' => 'client-'.uniqid().'@example.test',
                'password' => 'irrelevant-hashed-value',
                'is_active' => true,
            ]);

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            return ['firm' => $firm, 'client' => $client, 'matter' => $matter, 'portalUser' => $portalUser];
        });
    }

    private function withinClientPortalRequest(Firm $firm, callable $callback): mixed
    {
        $tenant = new TenantContextService;
        $tenant->setFirmContext($firm);
        $tenant->setDatabaseTenantContextForFirmId($firm->id);

        try {
            return $callback();
        } finally {
            $tenant->clearDatabaseTenantContext();
            $tenant->clearFirmContext();
        }
    }
}
