<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use App\Services\DocumentSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DocumentDownloadAuthorizationTest — Mission 1C (Security Validation,
 * Activation & Staging Proof), section 17: proves
 * DocumentSecurityService::canBeDownloadedBy() — the actor-scoped
 * authorization primitive any future download/signed-URL endpoint must
 * call — correctly composes the already-proven
 * MatterAccessPolicyService (internal firm users) and
 * ClientPortalMatterAccessPolicyService (Client Portal users), rather
 * than only proving firm-boundary membership the way canAccess() does.
 * No route, controller, or storage-layer code is added — this is the
 * primitive itself, not the feature.
 */
class DocumentDownloadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private DocumentSecurityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentSecurityService::class);
    }

    // ------------------------------------------------------------
    // Internal firm users — matter-scoped documents
    // ------------------------------------------------------------

    public function test_firm_owner_can_download_a_matter_scoped_document_without_an_assignment(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $owner = $this->makeFirmUser($firm, FirmUserRole::FirmOwner);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $owner->user));

        $this->assertTrue($allowed);
    }

    public function test_paralegal_without_a_matter_assignment_cannot_download_the_document(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $paralegal = $this->makeFirmUser($firm, FirmUserRole::Paralegal);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $paralegal->user));

        $this->assertFalse($allowed, 'A Paralegal in the right firm but with no MatterAssignment must not be able to download a matter-scoped document — this is exactly the IDOR gap canAccess() alone would miss.');
    }

    public function test_paralegal_with_an_active_matter_assignment_can_download_the_document(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $paralegal = $this->makeFirmUser($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($paralegal->user)->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $paralegal->user));

        $this->assertTrue($allowed);
    }

    public function test_a_removed_matter_assignment_no_longer_authorizes_download(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $paralegal = $this->makeFirmUser($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($paralegal->user)->create(['removed_at' => now()]));

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $paralegal->user));

        $this->assertFalse($allowed);
    }

    public function test_no_internal_user_can_download_a_document_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id, 'matter_id' => $matterB->id]));
        $ownerOfA = $this->makeFirmUser($firmA, FirmUserRole::FirmOwner);

        $allowed = $this->runWithFirmContext($firmB, fn () => $this->service->canBeDownloadedBy($documentB, $ownerOfA->user));

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // Internal firm users — firm-level documents (no matter_id)
    // ------------------------------------------------------------

    public function test_any_active_firm_staff_member_can_download_a_matterless_document_in_their_own_firm(): void
    {
        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => null]));
        $receptionist = $this->makeFirmUser($firm, FirmUserRole::Receptionist);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $receptionist->user));

        $this->assertTrue($allowed);
    }

    public function test_a_user_from_a_different_firm_cannot_download_a_matterless_document(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id, 'matter_id' => null]));
        $userOfA = $this->makeFirmUser($firmA, FirmUserRole::FirmOwner);

        $allowed = $this->runWithFirmContext($firmB, fn () => $this->service->canBeDownloadedBy($documentB, $userOfA->user));

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // Client Portal users — matter-scoped documents
    // ------------------------------------------------------------

    public function test_a_client_portal_user_with_a_matter_grant_can_download_a_document_on_that_matter(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $portalUser));

        $this->assertTrue($allowed);
    }

    public function test_a_client_portal_user_without_a_matter_grant_cannot_download_the_document(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $portalUser = $this->makePortalUser($client);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $portalUser));

        $this->assertFalse($allowed, 'A document must never be downloadable via an inferred matters.client_id match alone — an explicit grant is required, same rule as ClientPortalMatterAccessPolicyService itself.');
    }

    public function test_client_a_cannot_download_a_document_on_a_matter_granted_only_to_client_b(): void
    {
        $firm = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $portalUserA = $this->makePortalUser($clientA);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($clientB, $matter)->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $portalUserA));

        $this->assertFalse($allowed);
    }

    public function test_a_revoked_grant_no_longer_authorizes_download(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]));
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->revoked()->create());

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $portalUser));

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // Client Portal users — firm-level documents (no matter_id)
    // ------------------------------------------------------------

    public function test_a_client_portal_user_can_never_download_a_matterless_document_even_for_their_own_client(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => null, 'client_id' => $client->id]));
        $portalUser = $this->makePortalUser($client);

        $allowed = $this->runWithFirmContext($firm, fn () => $this->service->canBeDownloadedBy($document, $portalUser));

        $this->assertFalse($allowed, 'No matter-independent grant concept exists for Client Portal users — a matterless document must never be downloadable by a portal user, even one tied to the same client_id.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function makeFirmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->forFirm($firm)
            ->forUser(User::factory()->create())
            ->create(['role' => $role, 'status' => FirmUserStatus::Active]));
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
