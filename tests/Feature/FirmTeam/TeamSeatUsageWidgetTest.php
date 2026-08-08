<?php

declare(strict_types=1);

namespace Tests\Feature\FirmTeam;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Exceptions\FirmSeatLimitExceededException;
use App\Filament\Firm\Resources\FirmUserResource\Actions\InviteFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Pages\ListFirmUsers;
use App\Filament\Firm\Resources\FirmUserResource\Widgets\TeamSeatUsageWidget;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\FirmUserInvitationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TeamSeatUsageWidgetTest — Firm Feature Manifest §12 flat per-firm
 * seat model, UI proof. Proves the header widget renders the correct
 * "X of Y seats used" / "no licensed seats configured" text, and that
 * "+ Invite Team Member" is correctly allowed below capacity and fails
 * cleanly (via the service-level FirmSeatLimitExceededException, not a
 * UI-only guard) at capacity — matching this mission's established
 * "action stays clickable, fails cleanly with a clear message"
 * convention (see TeamSeatUsageWidget's own docblock for why hiding the
 * action was not chosen instead).
 */
final class TeamSeatUsageWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function actingAsOwner(Firm $firm): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    private function grantSeats(Firm $firm, int $seats): void
    {
        $this->runWithFirmContext($firm, function () use ($firm, $seats): void {
            $license = FirmLicense::query()->where('firm_id', $firm->id)->first();

            if ($license === null) {
                FirmLicense::factory()->forFirm($firm)->create(['purchased_seats' => $seats]);

                return;
            }

            $license->update(['purchased_seats' => $seats]);
        });
    }

    public function test_widget_shows_no_licensed_seats_configured_for_a_null_license_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsOwner($firm);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(TeamSeatUsageWidget::class));

        $test->assertSee('No licensed seats configured');
    }

    public function test_widget_shows_used_of_purchased_below_capacity(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsOwner($firm);
        $this->grantSeats($firm, 10);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(TeamSeatUsageWidget::class));

        $test->assertSee('1 of 10 seats used');
    }

    public function test_widget_shows_used_of_purchased_at_capacity(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsOwner($firm);
        $this->grantSeats($firm, 1);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(TeamSeatUsageWidget::class));

        $test->assertSee('1 of 1 seats used');
        $test->assertSee('All licensed seats are in use');
    }

    public function test_widget_is_included_as_a_header_widget_on_list_firm_users(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsOwner($firm);
        $this->grantSeats($firm, 5);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));

        $test->assertSee('Team Members');
        $test->assertSee('1 of 5 seats used');
    }

    public function test_invite_action_remains_visible_and_clickable_at_capacity(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsOwner($firm);
        $this->grantSeats($firm, 1); // owner alone already fills capacity

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));

        // The action stays visible/clickable at capacity — enforcement
        // is the service-level exception below, not a hidden button.
        $test->assertActionVisible(InviteFirmUserAction::getDefaultName());

        $this->expectException(FirmSeatLimitExceededException::class);

        app(FirmUserInvitationService::class)->invite(
            $firm,
            'blocked-'.uniqid().'@example.test',
            'Blocked',
            FirmUserRole::Attorney,
            $owner->user,
        );
    }

    public function test_invite_action_succeeds_below_capacity(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsOwner($firm);
        $this->grantSeats($firm, 2);

        Notification::fake();

        $firmUser = app(FirmUserInvitationService::class)->invite(
            $firm,
            'below-capacity-'.uniqid().'@example.test',
            'Below Capacity',
            FirmUserRole::Attorney,
            $owner->user,
        );

        $this->assertSame(FirmUserStatus::Invited, $firmUser->status);
    }
}
