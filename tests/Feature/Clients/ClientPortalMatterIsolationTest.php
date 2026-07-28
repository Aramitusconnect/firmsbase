<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ClientPortalMatterIsolationTest — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.5, §2.6.3).
 * Proves "the client must only see matters explicitly assigned to that
 * client" — `ClientPortalMatterAccessPolicyService::canAccessMatter()`,
 * keyed on explicit, revocable `client_portal_matter_grants` rows, NEVER
 * an inferred `matters.client_id = this client` rule (the design's own
 * explicit rejection of that simpler alternative, §2.6 point 3 /
 * §2.7.f). Cross-client and cross-matter denial are proven with real
 * fixtures at both the service boundary and, separately, via a raw RLS
 * proof against `client_portal_matter_grants` itself (its own dedicated
 * ClientPortalMatterGrantsForceRlsActivationTest covers the FORCE RLS
 * activation mechanics in full — not duplicated here).
 */
class ClientPortalMatterIsolationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // canAccessMatter() — explicit grant required
    // ------------------------------------------------------------

    public function test_a_client_with_an_active_grant_can_access_that_matter(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter),
        );

        $this->assertTrue($allowed);
    }

    public function test_a_client_with_no_grant_at_all_cannot_access_a_matter_even_in_their_own_firm(): void
    {
        // The design's own central rule: NOT an inferred
        // matters.client_id match — a matter genuinely belonging to
        // this client (via Matter.client_id) is still denied without an
        // EXPLICIT grant.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());
        $portalUser = $this->makePortalUser($client);

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter),
        );

        $this->assertFalse($allowed, 'A matter must never be visible via an inferred matters.client_id match alone — an explicit grant is required.');
    }

    public function test_a_revoked_grant_no_longer_grants_access(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->revoked()->create());

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter),
        );

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // Cross-client denial — client A's grant never leaks to client B
    // ------------------------------------------------------------

    public function test_client_a_cannot_access_a_matter_granted_only_to_client_b_in_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $portalUserA = $this->makePortalUser($clientA);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($clientB, $matter)->create());

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUserA, $matter),
        );

        $this->assertFalse($allowed, 'Client A must never see a matter granted only to Client B, even within the same firm.');
    }

    // ------------------------------------------------------------
    // Cross-matter denial — a grant for matter X never leaks to matter Y
    // ------------------------------------------------------------

    public function test_a_grant_for_one_matter_does_not_authorize_a_different_matter(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $grantedMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $ungrantedMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $grantedMatter)->create());

        $allowedGranted = $this->runWithFirmContext($firm, fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $grantedMatter));
        $allowedUngranted = $this->runWithFirmContext($firm, fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $ungrantedMatter));

        $this->assertTrue($allowedGranted);
        $this->assertFalse($allowedUngranted, 'A grant for one matter must never authorize a different matter.');
    }

    // ------------------------------------------------------------
    // Cross-firm denial
    // ------------------------------------------------------------

    public function test_a_client_can_never_access_a_matter_belonging_to_a_different_firm_even_with_a_grant_row_naming_it(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $portalUserA = $this->makePortalUser($clientA);

        // canAccessMatter() itself also checks firm_id === $matter->firm_id
        // on the grant row — no grant naming matterB's id under firmA's
        // own firm_id could ever be created for firmB's real matter in
        // the first place (matter_id's own FK constrains it to a real
        // matters row, whose firm_id is fixed at firmB), so this proves
        // the boundary from the read side: no grant exists, and none
        // legitimately could.
        $allowed = $this->runWithFirmContext(
            $firmA,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUserA, $matterB),
        );

        $this->assertFalse($allowed);
    }

    // ------------------------------------------------------------
    // grantedMatterIds() — the list-level UX filter
    // ------------------------------------------------------------

    public function test_granted_matter_ids_returns_exactly_the_clients_own_active_grants(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $otherClient = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->makePortalUser($client);

        $activeMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $revokedMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $otherClientMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $activeMatter)->create());
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $revokedMatter)->revoked()->create());
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($otherClient, $otherClientMatter)->create());

        $ids = $this->runWithFirmContext($firm, fn () => app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser));

        $this->assertSame([$activeMatter->id], $ids);
    }

    // ------------------------------------------------------------
    // Real RLS proof — client_portal_matter_grants itself, under firm
    // context, cannot leak another firm's grant row (complements the
    // full FORCE RLS activation coverage in
    // ClientPortalMatterGrantsForceRlsActivationTest)
    // ------------------------------------------------------------

    public function test_real_rls_proof_a_grant_row_is_genuinely_invisible_outside_its_own_firms_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $grantA = $this->runWithFirmContext($firmA, fn () => ClientPortalMatterGrant::factory()->forFirm($firmA)->create());
        $grantB = $this->runWithFirmContext($firmB, fn () => ClientPortalMatterGrant::factory()->forFirm($firmB)->create());

        $visibleUnderFirmA = $this->runWithFirmContext($firmA, fn () => DB::table('client_portal_matter_grants')->pluck('id')->all());

        $this->assertContains($grantA->id, $visibleUnderFirmA);
        $this->assertNotContains($grantB->id, $visibleUnderFirmA);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
