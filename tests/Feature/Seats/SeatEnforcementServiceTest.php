<?php

namespace Tests\Feature\Seats;

use App\Enums\FirmUserRole;
use App\Enums\SeatClass;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\SeatAllocationService;
use App\Services\SeatEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatEnforcementServiceTest extends TestCase
{
    use RefreshDatabase;

    private SeatEnforcementService $service;
    private SeatAllocationService $allocationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeatEnforcementService();
        $this->allocationService = new SeatAllocationService();
    }

    /**
     * Section 39A-3L, Checkpoint 9 — SeatEnforcementService::usageFor()/
     * canInvite() are deliberately NOT self-wrapped (both seat_allocations
     * and firm_users are FORCE RLS tables; see that service's own
     * docblock). Every test below now wraps its usageFor()/canInvite()
     * call in the test's own tenant context — matching
     * DowngradeEvaluationService::evaluate()'s local-wrap pattern —
     * instead of relying on ambient context left over from a preceding
     * FirmUser::factory()->forFirm($firm)->create() call in the same
     * test, which is exactly the fragile behavior that stopped working
     * correctly the instant allocateDirect() started properly clearing
     * its own context in a finally block.
     */
    public function test_firm_owner_and_attorney_roles_default_to_attorney_seat_class(): void
    {
        $firm = Firm::factory()->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create();
        $this->allocationService->allocateDirect($firm, SeatClass::Attorney, 5);

        $usage = $this->runWithFirmContext($firm, fn () => $this->service->usageFor($firm, SeatClass::Attorney));

        $this->assertSame(2, $usage->used);
    }

    public function test_paralegal_legal_assistant_receptionist_and_billing_staff_default_to_staff_seat_class(): void
    {
        $firm = Firm::factory()->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Paralegal)->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::LegalAssistant)->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Receptionist)->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::BillingStaff)->create();
        $this->allocationService->allocateDirect($firm, SeatClass::Staff, 10);

        $usage = $this->runWithFirmContext($firm, fn () => $this->service->usageFor($firm, SeatClass::Staff));

        $this->assertSame(4, $usage->used);
    }

    public function test_read_only_is_reached_only_by_an_explicit_seat_class_never_a_default(): void
    {
        $firm = Firm::factory()->create();
        // Attorney role, but explicitly assigned a read_only seat.
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create(['seat_class' => SeatClass::ReadOnly]);
        $this->allocationService->allocateDirect($firm, SeatClass::ReadOnly, 2);
        $this->allocationService->allocateDirect($firm, SeatClass::Attorney, 5);

        $readOnlyUsage = $this->runWithFirmContext($firm, fn () => $this->service->usageFor($firm, SeatClass::ReadOnly));
        $attorneyUsage = $this->runWithFirmContext($firm, fn () => $this->service->usageFor($firm, SeatClass::Attorney));

        $this->assertSame(1, $readOnlyUsage->used);
        $this->assertSame(0, $attorneyUsage->used, 'An explicit read_only seat_class must not also count as attorney.');
    }

    public function test_client_portal_users_are_never_counted_as_seats(): void
    {
        $firm = Firm::factory()->create();
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create();
        Client::factory()->create(['firm_id' => $firm->id]);
        Client::factory()->create(['firm_id' => $firm->id]);
        $this->allocationService->allocateDirect($firm, SeatClass::Attorney, 5);

        $usage = $this->runWithFirmContext($firm, fn () => $this->service->usageFor($firm, SeatClass::Attorney));

        $this->assertSame(1, $usage->used, 'Client rows must never contribute to seat usage.');
    }

    public function test_can_invite_is_false_once_the_allocation_is_exhausted(): void
    {
        $firm = Firm::factory()->create();
        $this->allocationService->allocateDirect($firm, SeatClass::Attorney, 1);
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create();

        $this->assertFalse($this->runWithFirmContext($firm, fn () => $this->service->canInvite($firm, SeatClass::Attorney)));
    }

    public function test_can_invite_is_true_when_seats_remain(): void
    {
        $firm = Firm::factory()->create();
        $this->allocationService->allocateDirect($firm, SeatClass::Attorney, 2);
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create();

        $this->assertTrue($this->runWithFirmContext($firm, fn () => $this->service->canInvite($firm, SeatClass::Attorney)));
    }
}
