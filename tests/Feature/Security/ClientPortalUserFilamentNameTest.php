<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Services\TenantContextService;
use Filament\Models\Contracts\HasName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every authenticated Client Portal page renders the signed-in user's name
 * through FilamentManager::getUserName(), which is typed `: string`.
 *
 * client_portal_users has no `name` column and ClientPortalService::activate()
 * only writes client_id/email/password/is_active, so before this contract was
 * implemented Filament fell back to a null attribute and the TypeError became
 * a 500 on the first page after login — for every client, not just an oddly
 * built fixture. Found on staging while creating that environment's first
 * client_portal_users rows.
 *
 * The name is sourced from the related Client, which is FORCE-RLS protected,
 * so the fallbacks below are the point of the test: a read that legitimately
 * returns null outside firm context must still yield a string.
 */
class ClientPortalUserFilamentNameTest extends TestCase
{
    use RefreshDatabase;

    private function portalUser(array $clientAttributes = []): array
    {
        $firm = Firm::factory()->create();

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $clientAttributes) {
            $client = Client::factory()->create(array_merge(
                ['firm_id' => $firm->id, 'display_name' => 'STAGING ACCEPTANCE CLIENT'],
                $clientAttributes,
            ));

            $portalUser = ClientPortalUser::query()->create([
                'client_id' => $client->id,
                'email' => 'portal-user@firmsbase-staging.internal',
                'password' => 'irrelevant-for-this-assertion',
                'is_active' => true,
            ]);

            return [$firm, $client, $portalUser];
        });
    }

    public function test_portal_user_satisfies_filaments_name_contract(): void
    {
        [, , $portalUser] = $this->portalUser();

        $this->assertInstanceOf(HasName::class, $portalUser);
    }

    public function test_name_comes_from_the_related_client_in_firm_context(): void
    {
        [$firm, $client, $portalUser] = $this->portalUser();

        (new TenantContextService)->runWithFirmContext($firm, function () use ($client, $portalUser) {
            $this->assertSame($client->display_name, $portalUser->fresh()->getFilamentName());
        });
    }

    public function test_name_never_returns_empty_when_the_client_is_unreadable(): void
    {
        // No firm context: Client is FORCE-RLS protected, so the relation
        // legitimately resolves to null. The panel must still render.
        [, , $portalUser] = $this->portalUser();

        $name = ClientPortalUser::query()->find($portalUser->id)?->getFilamentName()
            ?? $portalUser->getFilamentName();

        $this->assertIsString($name);
        $this->assertNotSame('', trim($name));
    }

    public function test_name_falls_back_when_client_display_name_is_blank(): void
    {
        [$firm, , $portalUser] = $this->portalUser(['display_name' => ' ']);

        (new TenantContextService)->runWithFirmContext($firm, function () use ($portalUser) {
            $name = $portalUser->fresh()->getFilamentName();

            $this->assertSame('portal-user', $name, 'Expected the email local-part fallback.');
            $this->assertNotSame('', trim($name));
        });
    }

    public function test_name_is_a_string_for_every_degenerate_combination(): void
    {
        // The return type is non-nullable; nothing about a half-populated
        // record may be able to violate it.
        $portalUser = new ClientPortalUser(['email' => '']);

        $this->assertSame('Client', $portalUser->getFilamentName());
    }
}
