<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DocumentDownloadControllerTest (Client Portal) — Follow-up 1 (Client
 * Portal Documents). Proves the real, session-authenticated
 * `client-portal.documents.download` route: a 200 with the real file
 * content and correct filename for an authorized client, and a
 * fail-closed 403/404 (never a silent leak of the file) for every
 * unauthorized case — client_visible=false, no grant, a revoked
 * grant, a different client, a different firm's client, a guessed
 * uuid, and (the isUsable() hardening fix this follow-up adds) an
 * unusable (still-scanning or rejected) document even when marked
 * client_visible=true with an active grant.
 *
 * DocumentSecurityService::canBeViewedInPortalBy() is already
 * exhaustively proven at the service layer by DocumentSecurityServiceTest
 * — this test proves that boundary is actually wired to the HTTP layer,
 * mirroring the Firm-side DocumentDownloadControllerTest's own shape.
 */
final class DocumentDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_download_a_shared_usable_granted_document(): void
    {
        Storage::fake('local');
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario();
        Storage::disk('local')->put($document->storage_path, 'a real client document body');

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertOk();
        $this->assertStringContainsString($document->original_filename, (string) $response->headers->get('content-disposition'));
        $this->assertSame('a real client document body', $response->streamedContent());
    }

    public function test_a_not_shared_document_cannot_be_downloaded_directly(): void
    {
        Storage::fake('local');
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(clientVisible: false);
        Storage::disk('local')->put($document->storage_path, 'a real client document body');

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertForbidden();
    }

    public function test_a_document_on_a_matter_with_no_grant_cannot_be_downloaded_even_though_it_is_genuinely_the_clients(): void
    {
        Storage::fake('local');
        $firm = Firm::factory()->create();
        [$client, $matter, $document] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $document = Document::factory()->clean()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'client_visible' => true,
                'status' => DocumentStatus::Approved,
            ]);

            // Deliberately NO ClientPortalMatterGrant row.
            return [$client, $matter, $document];
        });
        Storage::disk('local')->put($document->storage_path, 'a real client document body');
        $portalUser = $this->makePortalUser($client);

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertForbidden();
    }

    public function test_a_revoked_grant_blocks_the_download(): void
    {
        Storage::fake('local');
        $firm = Firm::factory()->create();
        [$client, $matter, $document] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $document = Document::factory()->clean()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'client_visible' => true,
                'status' => DocumentStatus::Approved,
            ]);

            ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->revoked()->create();

            return [$client, $matter, $document];
        });
        Storage::disk('local')->put($document->storage_path, 'a real client document body');
        $portalUser = $this->makePortalUser($client);

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertForbidden();
    }

    public function test_a_different_client_cannot_download_another_clients_shared_document(): void
    {
        Storage::fake('local');
        [$firm, $clientA, $matterA, $documentA] = $this->makeScenario();
        Storage::disk('local')->put($documentA->storage_path, 'a real client document body');
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUserB = $this->makePortalUser($clientB);

        $response = $this->actingAs($portalUserB, 'client')
            ->get($this->clientPortalUrl('/documents/'.$documentA->uuid.'/download'));

        $response->assertForbidden();
    }

    public function test_a_client_of_a_different_firm_cannot_download_the_document(): void
    {
        Storage::fake('local');
        [$firmA, $clientA, $matterA, $documentA] = $this->makeScenario();
        Storage::disk('local')->put($documentA->storage_path, 'a real client document body');
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $portalUserB = $this->makePortalUser($clientB);

        $response = $this->actingAs($portalUserB, 'client')
            ->get($this->clientPortalUrl('/documents/'.$documentA->uuid.'/download'));

        // A cross-firm document is resolved under the REQUESTING
        // client's own firm tenant context, so it is genuinely
        // invisible under RLS (404) rather than bound-then-rejected —
        // either way the file is never served.
        $response->assertNotFound();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_guessing_a_document_uuid_the_client_cannot_see_fails_closed(): void
    {
        Storage::fake('local');
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);

        // Not shared, no grant, no document with this uuid exists at
        // all — a pure guess.
        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.Str::uuid7().'/download'));

        $this->assertContains($response->getStatusCode(), [403, 404], 'A guessed uuid must fail closed, never leak the file.');
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_a_guest_is_redirected_to_login_not_given_the_file(): void
    {
        Storage::fake('local');
        [$firm, $client, $matter, $document] = $this->makeScenario();

        $response = $this->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * Follow-up 1's own hardening fix: an unusable document — still
     * scanning (Pending) — marked client_visible=true with an active
     * grant must still never be downloadable. Proves the isUsable()
     * check added to canBeViewedInPortalBy() actually closes the gap
     * at the real HTTP boundary, not just at the service layer.
     */
    public function test_a_pending_scan_document_cannot_be_downloaded_even_when_shared_and_granted(): void
    {
        Storage::fake('local');
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(
            scanStatus: DocumentScanStatus::Pending,
            status: DocumentStatus::Uploaded,
        );
        Storage::disk('local')->put($document->storage_path, 'a real client document body');

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertForbidden();
    }

    /**
     * Same hardening fix, the rejected/infected variant.
     */
    public function test_a_rejected_document_cannot_be_downloaded_even_when_shared_and_granted(): void
    {
        Storage::fake('local');
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(
            scanStatus: DocumentScanStatus::Infected,
            status: DocumentStatus::Rejected,
        );
        Storage::disk('local')->put($document->storage_path, 'a real client document body');

        $response = $this->actingAs($portalUser, 'client')
            ->get($this->clientPortalUrl('/documents/'.$document->uuid.'/download'));

        $response->assertForbidden();
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Client, 2: Matter, 3: Document, 4: ClientPortalUser}
     */
    private function makeScenario(
        bool $clientVisible = true,
        DocumentScanStatus $scanStatus = DocumentScanStatus::Clean,
        DocumentStatus $status = DocumentStatus::Approved,
    ): array {
        $firm = Firm::factory()->create();
        [$client, $matter, $document] = $this->runWithFirmContext($firm, function () use ($firm, $clientVisible, $scanStatus, $status) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $document = Document::factory()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'client_visible' => $clientVisible,
                'scan_status' => $scanStatus,
                'status' => $status,
                'scanned_at' => now(),
                'storage_disk' => 'local',
                'storage_path' => "documents/{$firm->id}/{$matter->id}/".Str::uuid7().'-evidence.pdf',
                'original_filename' => 'evidence.pdf',
            ]);

            ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create();

            return [$client, $matter, $document];
        });
        $portalUser = $this->makePortalUser($client);

        return [$firm, $client, $matter, $document, $portalUser];
    }

    private function makePortalUser(Client $client): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => 'client-'.Str::random(8).'@example.test',
            'password' => 'irrelevant-hashed-value',
            'is_active' => true,
        ]));
    }
}
