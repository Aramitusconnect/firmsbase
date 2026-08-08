<?php

declare(strict_types=1);

namespace Tests\Feature\FirmTeam;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\FirmSeatCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmSeatCapacityServiceTest — Firm Feature Manifest §12's flat
 * per-firm seat model. Proves purchasedSeats()/usedSeats()/
 * remainingSeats()/canInvite() across Active/Invited/Suspended/Removed
 * status combinations, that the owner alone counts as 1 used seat, and
 * that a null-license firm reports purchasedSeats() === null and
 * canInvite() === false.
 */
final class FirmSeatCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FirmSeatCapacityService
    {
        return app(FirmSeatCapacityService::class);
    }

    private function licensedFirm(int $purchasedSeats): Firm
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create(['purchased_seats' => $purchasedSeats]));

        return $firm;
    }

    private function firmUser(Firm $firm, FirmUserRole $role, FirmUserStatus $status): FirmUser
    {
        return $this->createWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create(['status' => $status]),
        );
    }

    // ------------------------------------------------------------
    // purchasedSeats()
    // ------------------------------------------------------------

    public function test_purchased_seats_is_null_for_a_firm_with_no_license(): void
    {
        $firm = Firm::factory()->create();

        $this->assertNull($this->service()->purchasedSeats($firm));
    }

    public function test_purchased_seats_reads_the_licenses_column(): void
    {
        $firm = $this->licensedFirm(10);

        $this->assertSame(10, $this->service()->purchasedSeats($firm));
    }

    public function test_can_invite_is_false_for_a_null_license_firm(): void
    {
        $firm = Firm::factory()->create();

        $this->assertFalse($this->service()->canInvite($firm));
    }

    // ------------------------------------------------------------
    // usedSeats() — owner alone, and each status
    // ------------------------------------------------------------

    public function test_owner_alone_counts_as_one_used_seat(): void
    {
        $firm = $this->licensedFirm(5);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);

        $this->assertSame(1, $this->service()->usedSeats($firm));
        $this->assertSame(4, $this->service()->remainingSeats($firm));
    }

    public function test_active_invited_and_suspended_all_consume_a_seat(): void
    {
        $firm = $this->licensedFirm(10);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);
        $this->firmUser($firm, FirmUserRole::Attorney, FirmUserStatus::Active);
        $this->firmUser($firm, FirmUserRole::Paralegal, FirmUserStatus::Invited);
        $this->firmUser($firm, FirmUserRole::BillingStaff, FirmUserStatus::Suspended);

        $this->assertSame(4, $this->service()->usedSeats($firm));
        $this->assertSame(6, $this->service()->remainingSeats($firm));
    }

    public function test_removed_rows_do_not_consume_a_seat(): void
    {
        $firm = $this->licensedFirm(5);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);
        $this->firmUser($firm, FirmUserRole::Attorney, FirmUserStatus::Removed);
        $this->firmUser($firm, FirmUserRole::Receptionist, FirmUserStatus::Removed);

        $this->assertSame(1, $this->service()->usedSeats($firm), 'Removed rows must never count toward used seats.');
        $this->assertSame(4, $this->service()->remainingSeats($firm));
    }

    public function test_a_removed_row_frees_the_seat_a_new_invitee_can_then_use(): void
    {
        $firm = $this->licensedFirm(2);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);
        $second = $this->firmUser($firm, FirmUserRole::Attorney, FirmUserStatus::Active);

        $this->assertFalse($this->service()->canInvite($firm), 'At capacity: owner + 1 attorney fill both purchased seats.');

        $this->createWithFirmContext($firm, function () use ($second) {
            $fresh = FirmUser::query()->find($second->id);
            $fresh->update(['status' => FirmUserStatus::Removed]);
        });

        $this->assertSame(1, $this->service()->usedSeats($firm));
        $this->assertTrue($this->service()->canInvite($firm), 'Removing the attorney must free their seat.');
    }

    // ------------------------------------------------------------
    // canInvite() / remainingSeats() at exact capacity
    // ------------------------------------------------------------

    public function test_can_invite_is_false_exactly_at_capacity(): void
    {
        $firm = $this->licensedFirm(1);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);

        $this->assertSame(0, $this->service()->remainingSeats($firm));
        $this->assertFalse($this->service()->canInvite($firm));
    }

    public function test_can_invite_is_true_below_capacity(): void
    {
        $firm = $this->licensedFirm(2);
        $this->firmUser($firm, FirmUserRole::FirmOwner, FirmUserStatus::Active);

        $this->assertTrue($this->service()->canInvite($firm));
    }

    // ------------------------------------------------------------
    // Cross-firm isolation
    // ------------------------------------------------------------

    public function test_one_firms_seat_usage_never_affects_another_firms(): void
    {
        $firmA = $this->licensedFirm(3);
        $firmB = $this->licensedFirm(3);

        $this->firmUser($firmA, FirmUserRole::FirmOwner, FirmUserStatus::Active);
        $this->firmUser($firmA, FirmUserRole::Attorney, FirmUserStatus::Active);
        $this->firmUser($firmA, FirmUserRole::Paralegal, FirmUserStatus::Active);

        $this->firmUser($firmB, FirmUserRole::FirmOwner, FirmUserStatus::Active);

        $this->assertSame(3, $this->service()->usedSeats($firmA));
        $this->assertSame(1, $this->service()->usedSeats($firmB), "Firm B's usage must be unaffected by Firm A's users.");
        $this->assertFalse($this->service()->canInvite($firmA));
        $this->assertTrue($this->service()->canInvite($firmB));
    }

    /**
     * RLS regression checklist: firm_licenses carries permanent FORCE
     * ROW LEVEL SECURITY (2026_08_25_930019_force_rls_on_firm_licenses_table).
     * A direct raw read of another firm's purchased_seats, under a
     * DIFFERENT firm's own tenant context, must return nothing — never
     * leak the value or silently resolve to the wrong firm's row.
     */
    public function test_cross_firm_purchased_seats_is_not_readable_under_the_wrong_tenant_context(): void
    {
        $firmA = $this->licensedFirm(50);
        $firmB = Firm::factory()->create();

        $leaked = $this->runWithFirmContext($firmB, fn () => FirmLicense::query()->where('firm_id', $firmA->id)->first());

        $this->assertNull($leaked, "Firm A's license must be structurally invisible under Firm B's own tenant context.");
    }

    /**
     * A raw UPDATE attempt against another firm's FirmLicense row, run
     * under the WRONG tenant context, must affect zero rows — the
     * FORCE-RLS UPDATE policy's own USING clause silently excludes any
     * row that does not match the active app.current_firm_id, it never
     * raises and it never touches the row.
     */
    public function test_cross_firm_purchased_seats_cannot_be_set_under_the_wrong_tenant_context(): void
    {
        $firmA = $this->licensedFirm(50);
        $firmB = Firm::factory()->create();

        $affectedRows = $this->runWithFirmContext(
            $firmB,
            fn () => DB::table('firm_licenses')->where('firm_id', $firmA->id)->update(['purchased_seats' => 999]),
        );

        $this->assertSame(0, $affectedRows, 'An UPDATE against a foreign firm\'s license row must affect zero rows under the wrong tenant context.');

        $fresh = $this->runWithFirmContext($firmA, fn () => FirmLicense::query()->where('firm_id', $firmA->id)->first());
        $this->assertSame(50, $fresh->purchased_seats, "Firm A's own value must be completely unaffected by the blocked cross-firm write attempt.");
    }
}
