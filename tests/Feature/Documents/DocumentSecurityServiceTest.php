<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentSecurityService;
use App\Services\DocumentUploadPolicyService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DocumentSecurityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentSecurityService $service;

    private FakeVirusScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentSecurityService(new DocumentUploadPolicyService, app(DomainEventRecorderService::class));
        $this->scanner = new FakeVirusScanner;
    }

    public function test_upload_creates_a_document_pending_scan(): void
    {
        $firm = Firm::factory()->create();

        $document = $this->service->upload(
            $firm,
            'passport.pdf',
            'application/pdf',
            2048,
            'local',
            'documents/passport.pdf',
            hash('sha256', 'x'),
        );

        $this->assertSame(DocumentStatus::Uploaded, $document->status);
        $this->assertSame(DocumentScanStatus::Pending, $document->scan_status);
    }

    public function test_upload_rejects_a_dangerous_extension_before_creating_any_document_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service->upload($firm, 'malware.exe', 'application/octet-stream', 2048, 'local', 'documents/malware.exe', hash('sha256', 'x'));
        } finally {
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_an_infected_scan_result_blocks_the_document_as_rejected(): void
    {
        $firm = Firm::factory()->create();

        $document = $this->service->upload(
            $firm,
            'infected-eicar.pdf',
            'application/pdf',
            2048,
            'local',
            'documents/infected-eicar.pdf',
            hash('sha256', 'x'),
        );

        $result = $this->scanner->scan($document->storage_disk, $document->storage_path);
        $this->assertSame(DocumentScanStatus::Infected, $result->status);

        $scanned = $this->service->applyScanResult($document, $result);

        $this->assertSame(DocumentScanStatus::Infected, $scanned->scan_status);
        $this->assertSame(DocumentStatus::Rejected, $scanned->status);
        $this->assertNotNull($scanned->rejected_reason);
        $this->assertFalse($scanned->isUsable());
    }

    public function test_a_failed_scan_does_not_reject_the_document_but_is_not_usable(): void
    {
        $firm = Firm::factory()->create();

        $document = $this->service->upload(
            $firm,
            'scanfail-doc.pdf',
            'application/pdf',
            2048,
            'local',
            'documents/scanfail-doc.pdf',
            hash('sha256', 'x'),
        );

        $result = $this->scanner->scan($document->storage_disk, $document->storage_path);
        $scanned = $this->service->applyScanResult($document, $result);

        $this->assertSame(DocumentScanStatus::Failed, $scanned->scan_status);
        $this->assertNotSame(DocumentStatus::Rejected, $scanned->status);
        $this->assertFalse($scanned->isUsable());
    }

    public function test_a_clean_scan_makes_the_document_usable_and_approvable(): void
    {
        $firm = Firm::factory()->create();
        $reviewer = User::factory()->create();

        $document = $this->service->upload(
            $firm,
            'clean-doc.pdf',
            'application/pdf',
            2048,
            'local',
            'documents/clean-doc.pdf',
            hash('sha256', 'x'),
        );

        $scanned = $this->service->applyScanResult($document, $this->scanner->scan($document->storage_disk, $document->storage_path));

        $this->assertTrue($scanned->isUsable());

        $approved = $this->service->approve($scanned, $reviewer);

        $this->assertSame(DocumentStatus::Approved, $approved->status);
    }

    public function test_approve_is_refused_when_the_document_is_not_usable(): void
    {
        $firm = Firm::factory()->create();
        $reviewer = User::factory()->create();

        $document = Document::factory()->create([
            'firm_id' => $firm->id,
            'scan_status' => DocumentScanStatus::Infected,
            'status' => DocumentStatus::Rejected,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($document, $reviewer);
    }

    public function test_can_access_requires_matching_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $document = Document::factory()->create(['firm_id' => $firm->id]);

        $this->assertTrue($this->service->canAccess($document, $firm));
        $this->assertFalse($this->service->canAccess($document, $otherFirm));
    }

    // ------------------------------------------------------------
    // canBeViewedInPortalBy() — Mission 3 (Document Center
    // Completion), section 3.4.
    // ------------------------------------------------------------

    public function test_a_visible_document_on_a_granted_matter_can_be_viewed_in_the_portal(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'client_visible' => true,
        ]));
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeViewedInPortalBy($document, $portalUser));

        $this->assertTrue($allowed);
    }

    public function test_a_visible_document_without_a_matter_grant_cannot_be_viewed_in_the_portal(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'client_visible' => true,
        ]));
        $portalUser = $this->makePortalUser($client);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeViewedInPortalBy($document, $portalUser));

        $this->assertFalse($allowed, 'client_visible=true alone must never authorize portal access — a real matter grant is still required.');
    }

    public function test_a_granted_but_not_shared_document_cannot_be_viewed_in_the_portal(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'client_visible' => false,
        ]));
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeViewedInPortalBy($document, $portalUser));

        $this->assertFalse($allowed, 'A matter grant alone must never expose a document the firm has not explicitly shared.');
    }

    public function test_a_matterless_document_can_never_be_viewed_in_the_portal_even_if_marked_visible(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => null,
            'client_id' => $client->id,
            'client_visible' => true,
        ]));
        $portalUser = $this->makePortalUser($client);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeViewedInPortalBy($document, $portalUser));

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // setClientVisibility() — Mission 3, section 3.4.
    // ------------------------------------------------------------

    public function test_set_client_visibility_toggles_and_persists_the_flag(): void
    {
        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'client_visible' => false,
        ]));

        $shared = $this->runWithFirmContext($firm, fn () => $this->service->setClientVisibility($document, true));
        $this->assertTrue($shared->client_visible);
        $this->assertTrue($this->runWithFirmContext($firm, fn () => $document->fresh())->client_visible);

        $unshared = $this->runWithFirmContext($firm, fn () => $this->service->setClientVisibility($document, false));
        $this->assertFalse($unshared->client_visible);
        $this->assertFalse($this->runWithFirmContext($firm, fn () => $document->fresh())->client_visible);
    }

    private function makePortalUser(Client $client, array $overrides = []): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create(array_merge([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ], $overrides)));
    }
}
