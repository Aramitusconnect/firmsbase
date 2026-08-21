<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource\Actions\InvitePortalAccessAction;
use App\Filament\Firm\Resources\ClientResource\Pages\ViewClient;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\ConsentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * InvitePortalAccessActionTest — Mission 4 (Client Portal Activation),
 * finding 4.2. Proves InvitePortalAccessAction actually routes through
 * ClientPortalService::invite() — never a bare mutation of
 * portal_status/portal_invitation_token — by asserting the same
 * observable effects ClientPortalServiceTest itself proves for
 * invite() directly: portal_status flips to Invited, a fresh
 * portal_invitation_token is stamped, and the RuntimeException
 * invite() throws when no granted portal consent exists on file
 * surfaces as a Notification rather than an unhandled exception.
 */
class InvitePortalAccessActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_invite_action_calls_client_portal_service_invite_and_flips_portal_status(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => app(ConsentService::class)->capture($firm, $client->id, ConsentChannel::Portal, 'v1'));

        $this->assertSame(ClientPortalStatus::NotInvited, $client->portal_status);

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()]);
            $test->callAction(InvitePortalAccessAction::getDefaultName());
            $test->assertNotified('Portal invitation sent');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Client::query()->find($client->id));

        $this->assertSame(ClientPortalStatus::Invited, $fresh->portal_status);
        $this->assertNotNull($fresh->portal_invitation_token);
        $this->assertNotNull($fresh->portal_invitation_sent_at);
    }

    public function test_invite_action_surfaces_a_notification_when_consent_is_not_granted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // Deliberately no ConsentService::capture() call — invite()
        // must throw, and the Action must convert it into a
        // Notification, never an unhandled exception.
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()]);
            $test->callAction(InvitePortalAccessAction::getDefaultName());
            $test->assertNotified('Could not invite client to the portal');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Client::query()->find($client->id));

        $this->assertSame(ClientPortalStatus::NotInvited, $fresh->portal_status, 'portal_status must not change when invite() throws.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
