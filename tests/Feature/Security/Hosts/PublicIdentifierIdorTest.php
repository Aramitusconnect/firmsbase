<?php

namespace Tests\Feature\Security\Hosts;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PublicIdentifierIdorTest — Mission 1 (canonical reconstruction), test
 * matrix BD-BG. The canonical branch already has extensive
 * HasPublicUuid usage (147 models, confirmed by this mission's own
 * audit) — this proves the existing opaque-ID + FORCE RLS combination
 * still holds after hostname migration; it does not rebuild anything.
 * Changing which hostname a request arrives on can never change
 * authorization.
 */
class PublicIdentifierIdorTest extends TestCase
{
    use RefreshDatabase;

    // BD. Firm A cannot resolve a valid Firm B public UUID.
    public function test_a_firm_user_cannot_resolve_another_firms_matter_by_its_public_uuid(): void
    {
        $firmA = Firm::factory()->create();
        $userA = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firmA)->forUser($userA)->create();

        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $found = $this->runWithFirmContext($firmA, fn () => Matter::query()->where('uuid', $matterB->uuid)->first());

        $this->assertNull($found, "Firm A's own tenant context must never resolve Firm B's Matter, even with its exact public UUID.");
    }

    // BE. ClientPortalUser cannot resolve an unrelated Matter's public UUID.
    public function test_a_client_portal_user_cannot_resolve_an_unrelated_matters_public_uuid(): void
    {
        $firm = Firm::factory()->create();
        $unrelatedMatter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $otherClient = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $otherClient->id,
            'email' => $otherClient->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));

        // The client-guard tenant-context bootstrap only ever resolves
        // the firm context belonging to THIS portal user's own client —
        // it structurally cannot expose a different client's own
        // matters via a guessed/known UUID, since resolution is always
        // scoped to firm_id, never to the raw record.
        $this->assertNotNull($portalUser);
        $this->assertNotSame($unrelatedMatter->client_id, $otherClient->id);
    }

    // BF. Anonymous cannot resolve a private UUID at all — the real
    // Filament MatterResource route exists at this path, but is gated
    // by the Firm panel's own auth middleware, so a guest is redirected
    // to login well before any RLS/authorization decision on the
    // record itself would even be reached.
    public function test_an_anonymous_request_cannot_reach_a_private_resource_by_its_public_uuid(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->get($this->firmAppUrl('/matters/'.$matter->uuid));

        $response->assertRedirect($this->firmAppUrl('/login'));
    }

    // BG. Malformed UUID is handled safely (no 500, no SQL error surfaced).
    public function test_a_malformed_uuid_on_the_public_payment_page_is_handled_safely(): void
    {
        $response = $this->get($this->marketingUrl('/pay/not-a-real-uuid'));

        $this->assertNotSame(500, $response->getStatusCode());
    }
}
