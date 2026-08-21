<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Filament\ClientPortal\Resources\DocumentResource;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientPortalDocumentResourceTest — Follow-up 1 (Client Portal
 * Documents). Proves DocumentResource::getEloquentQuery() (the
 * list-level UX filter, a query-time restriction, never a post-load
 * check) is scoped to EXACTLY the composed
 * DocumentSecurityService::canBeViewedInPortalBy() boundary:
 * client_visible=true AND an active ClientPortalMatterAccessPolicyService
 * grant on the document's matter AND isUsable() (scan_status=Clean,
 * status!=Rejected). Mirrors ClientPortalMatterResourceTest's own
 * structure and its "resource/pages never reference an internal-only
 * field" scan.
 */
class ClientPortalDocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shared_usable_document_on_a_granted_matter_is_listed(): void
    {
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario();

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertContains($document->id, $ids);
    }

    public function test_a_not_shared_document_is_never_listed(): void
    {
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(clientVisible: false);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($document->id, $ids, 'client_visible=false must never be listed.');
    }

    public function test_a_document_on_a_matter_with_no_grant_is_never_listed_even_though_it_is_genuinely_the_clients(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter, $document] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $document = Document::factory()->clean()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'client_visible' => true,
            ]);

            // Deliberately NO ClientPortalMatterGrant row.
            return [$client, $matter, $document];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($document->id, $ids, 'An explicit matter grant is required — Document.client_id/matter_id alone must never authorize.');
    }

    public function test_a_revoked_grant_removes_the_document_from_the_list(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter, $document] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $document = Document::factory()->clean()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'client_visible' => true,
            ]);

            ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->revoked()->create();

            return [$client, $matter, $document];
        });
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($document->id, $ids, 'A revoked grant must remove portal visibility.');
    }

    public function test_a_different_client_cannot_see_another_clients_shared_document(): void
    {
        [$firm, $clientA, $matterA, $documentA] = $this->makeScenario();
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUserB = $this->makePortalUser($clientB);

        Auth::guard('client')->login($portalUserB);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($documentA->id, $ids, 'Client B must never see Client A\'s shared document.');
    }

    public function test_a_client_of_a_different_firm_cannot_see_the_document(): void
    {
        [$firmA, $clientA, $matterA, $documentA] = $this->makeScenario();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $portalUserB = $this->makePortalUser($clientB);

        Auth::guard('client')->login($portalUserB);

        $ids = $this->runWithFirmContext($firmB, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($documentA->id, $ids, 'A client of a different firm must never see the document, even under their own firm\'s context.');
    }

    /**
     * Follow-up 1's own hardening fix: an unusable document (still
     * scanning, or rejected) marked client_visible=true and granted
     * must still never appear — proves DocumentResource's query filter
     * actually inherits the isUsable() gap fix applied to
     * DocumentSecurityService::canBeViewedInPortalBy().
     */
    public function test_a_pending_scan_document_is_never_listed_even_when_shared_and_granted(): void
    {
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(scanStatus: DocumentScanStatus::Pending);

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($document->id, $ids, 'A still-scanning document must never be portal-visible, regardless of client_visible/grant.');
    }

    public function test_a_rejected_document_is_never_listed_even_when_shared_and_granted(): void
    {
        [$firm, $client, $matter, $document, $portalUser] = $this->makeScenario(
            scanStatus: DocumentScanStatus::Infected,
            status: DocumentStatus::Rejected,
        );

        Auth::guard('client')->login($portalUser);

        $ids = $this->runWithFirmContext($firm, fn () => DocumentResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($document->id, $ids, 'A rejected document must never be portal-visible, regardless of client_visible/grant.');
    }

    public function test_document_resource_and_its_pages_never_reference_internal_only_fields(): void
    {
        // scan_status/status are deliberately NOT in this list —
        // DocumentResource::getEloquentQuery() legitimately filters by
        // them (the isUsable() gate) without ever displaying them; the
        // leak this test guards against is a table/infolist column
        // exposing internal workflow/scan state, not a query-time
        // WHERE clause reading it.
        $forbidden = [
            'uploaded_by',
            'uploadedBy',
            'approved_by',
            'approvedBy',
            'approved_at',
            'rejected_reason',
            'scan_result_detail',
            'scanned_at',
            'file_hash',
            'storage_disk',
            'storage_path',
            'encryption_key_id',
            'document_request_item_id',
            'marketplace_intake_id',
        ];

        foreach ([
            app_path('Filament/ClientPortal/Resources/DocumentResource.php'),
            app_path('Filament/ClientPortal/Resources/DocumentResource/Pages/ListDocuments.php'),
            app_path('Filament/ClientPortal/Resources/DocumentResource/Pages/ViewDocument.php'),
        ] as $file) {
            $this->assertFileExists($file);
            $code = $this->stripComments((string) file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$file} must never reference {$needle}.");
            }
        }
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
            ]);

            ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create();

            return [$client, $matter, $document];
        });
        $portalUser = $this->makePortalUser($client);

        return [$firm, $client, $matter, $document, $portalUser];
    }

    /**
     * Strips comments/docblocks via PHP's own tokenizer first, mirroring
     * ClientPortalMatterResourceTest's identical technique, so a
     * docblock discussing a forbidden field name in prose never
     * false-positives a naive string-contains scan.
     */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
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
