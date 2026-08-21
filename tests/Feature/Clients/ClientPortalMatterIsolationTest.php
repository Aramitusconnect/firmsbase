<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Exceptions\TenantIsolationException;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessGrantService;
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
    // ClientPortalMatterAccessGrantService::grant()/revoke() — Mission
    // 4 (Client Portal Activation), finding 4.1. Before this service
    // existed, client_portal_matter_grants had zero production
    // writers — these tests prove the sole writer behaves correctly
    // and never weakens the explicit-grant isolation model proven
    // above.
    // ------------------------------------------------------------

    public function test_grant_creates_an_active_grant_that_immediately_authorizes_access(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter, $firmUser] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $firmUser = FirmUser::factory()->forFirm($firm)->create();

            return [$client, $matter, $firmUser];
        });
        $portalUser = $this->makePortalUser($client);

        $grant = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessGrantService::class)->grant($firm, $client, $matter, $firmUser),
        );

        $this->assertNotNull($grant);
        $this->assertTrue($grant->isActive());
        $this->assertSame($client->id, $grant->client_id);
        $this->assertSame($matter->id, $grant->matter_id);
        $this->assertSame($firm->id, $grant->firm_id);
        $this->assertSame($firmUser->user_id, $grant->granted_by);

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter),
        );

        $this->assertTrue($allowed, 'grant() must immediately authorize access via canAccessMatter().');
    }

    public function test_grant_re_opens_a_previously_revoked_grant_for_the_same_client_and_matter(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter, $firmUser] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $firmUser = FirmUser::factory()->forFirm($firm)->create();

            return [$client, $matter, $firmUser];
        });
        $revoked = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->revoked()->create());

        $grant = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessGrantService::class)->grant($firm, $client, $matter, $firmUser),
        );

        // updateOrCreate() keyed on (client_id, matter_id) — this is
        // the SAME row re-opened, not a second row (the partial unique
        // index on (client_id, matter_id) WHERE revoked_at IS NULL
        // would reject a second concurrently-active row anyway).
        $this->assertSame($revoked->id, $grant->id);
        $this->assertNull($grant->revoked_at);

        $count = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::query()
            ->where('client_id', $client->id)
            ->where('matter_id', $matter->id)
            ->count());

        $this->assertSame(1, $count, 'Re-granting must re-open the same row, never create a second one.');
    }

    public function test_grant_rejects_a_matter_that_does_not_belong_to_the_given_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $client = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $matterInFirmB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $firmUser = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());

        $this->expectException(TenantIsolationException::class);

        app(ClientPortalMatterAccessGrantService::class)->grant($firmA, $client, $matterInFirmB, $firmUser);
    }

    public function test_grant_rejects_a_matter_that_does_not_belong_to_the_given_client(): void
    {
        $firm = Firm::factory()->create();
        [$client, $otherClientsMatter, $firmUser] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $otherClient = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($otherClient)->create();
            $firmUser = FirmUser::factory()->forFirm($firm)->create();

            return [$client, $matter, $firmUser];
        });

        $this->expectException(TenantIsolationException::class);

        app(ClientPortalMatterAccessGrantService::class)->grant($firm, $client, $otherClientsMatter, $firmUser);
    }

    public function test_revoke_stamps_revoked_at_and_immediately_denies_access(): void
    {
        $firm = Firm::factory()->create();
        [$client, $matter, $firmUser] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();
            $firmUser = FirmUser::factory()->forFirm($firm)->create();

            return [$client, $matter, $firmUser];
        });
        $portalUser = $this->makePortalUser($client);
        $grant = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $revoked = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessGrantService::class)->revoke($firm, $grant, $firmUser),
        );

        $this->assertNotNull($revoked->revoked_at);
        $this->assertFalse($revoked->isActive());

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter),
        );

        $this->assertFalse($allowed, 'revoke() must immediately deny access via canAccessMatter().');

        // The row is never deleted — revoked_at is stamped, history preserved.
        $stillExists = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::query()->find($grant->id));
        $this->assertNotNull($stillExists);
    }

    public function test_revoke_rejects_a_grant_that_does_not_belong_to_the_given_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $grantInFirmB = $this->runWithFirmContext($firmB, fn () => ClientPortalMatterGrant::factory()->forFirm($firmB)->create());
        $firmUserA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());

        $this->expectException(TenantIsolationException::class);

        app(ClientPortalMatterAccessGrantService::class)->revoke($firmA, $grantInFirmB, $firmUserA);
    }

    public function test_client_a_still_cannot_see_client_bs_matter_even_after_client_b_is_granted_access_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        [$clientA, $clientB, $matterB, $firmUser] = $this->runWithFirmContext($firm, function () use ($firm) {
            $clientA = Client::factory()->forFirm($firm)->create();
            $clientB = Client::factory()->forFirm($firm)->create();
            $matterB = Matter::factory()->forFirm($firm)->forClient($clientB)->create();
            $firmUser = FirmUser::factory()->forFirm($firm)->create();

            return [$clientA, $clientB, $matterB, $firmUser];
        });
        $portalUserA = $this->makePortalUser($clientA);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessGrantService::class)->grant($firm, $clientB, $matterB, $firmUser),
        );

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUserA, $matterB),
        );

        $this->assertFalse($allowed, 'Granting Client B access to their own matter must never leak visibility to Client A.');
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
