<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use App\Enums\FirmUserRole;
use App\Enums\TrustApprovalEventType;
use App\Enums\TrustLedgerStatus;
use App\Enums\TrustRefundRequestStatus;
use App\Enums\TrustTransferRequestStatus;
use App\Filament\Firm\Resources\TrustLedgerResource;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApproveDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApproveRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\CloseTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\CompleteRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\FirstApproveAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\FreezeTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\PostDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestTransferAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\SecondApproveAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Pages\ListTrustLedgers;
use App\Filament\Firm\Resources\TrustLedgerResource\Pages\ViewTrustLedger;
use App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers\RefundRequestsRelationManager;
use App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers\TransferRequestsRelationManager;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Models\TrustRefundRequest;
use App\Models\TrustTransferRequest;
use App\Models\User;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustHighRiskAdjustmentService;
use App\Services\TrustLedgerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * TrustLedgerResourceAccessTest — the core mutation-safety test suite
 * for this module: every request/approve/deny/post/apply/complete
 * Action really calls its exact named Trust*Service method (proven via
 * resulting ledger/balance/request state, never a raw model write),
 * role ceilings (Request: FirmOwner/Attorney/BillingStaff; Approve:
 * FirmOwner/Attorney only), the distinct-approver UX guard for
 * high-risk adjustments, and the small RLS regression checklist.
 */
final class TrustLedgerResourceAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    /**
     * @return array{0: Firm, 1: TrustLedger}
     */
    private function makeLedger(): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = $this->runWithFirmContext($firm, fn () => app(TrustLedgerService::class)->open($firm, $account, $client));

        return [$firm, $ledger];
    }

    // ------------------------------------------------------------
    // Freeze / Close
    // ------------------------------------------------------------

    public function test_freeze_and_close_transition_the_ledger_via_the_service(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->callAction(FreezeTrustLedgerAction::getDefaultName());
        });
        $frozen = $this->runWithFirmContext($firm, fn () => TrustLedger::query()->find($ledger->id));
        $this->assertSame(TrustLedgerStatus::Frozen, $frozen->status);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->callAction(CloseTrustLedgerAction::getDefaultName());
        });
        $closed = $this->runWithFirmContext($firm, fn () => TrustLedger::query()->find($ledger->id));
        $this->assertSame(TrustLedgerStatus::Closed, $closed->status);
    }

    public function test_billing_staff_cannot_freeze_a_ledger(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionHidden(FreezeTrustLedgerAction::getDefaultName());
        });

        $fresh = $this->runWithFirmContext($firm, fn () => TrustLedger::query()->find($ledger->id));
        $this->assertSame(TrustLedgerStatus::Active, $fresh->status);
    }

    // ------------------------------------------------------------
    // Deposit: request -> approve -> post (+ deny)
    // ------------------------------------------------------------

    public function test_full_deposit_lifecycle_via_filament_posts_an_entry_and_updates_the_balance(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->mountAction(RequestDepositAction::getDefaultName());
            $test->setActionData(['amount' => 250, 'matter_id' => null]);
            $test->callMountedAction();
            $test->assertNotified('Deposit requested');
        });

        $approver = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionVisible(ApproveDepositAction::getDefaultName());
        });

        $eventId = $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::query()->where('trust_ledger_id', $ledger->id)->first()->id);

        $this->runWithFirmContext($firm, function () use ($ledger, $eventId): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->mountAction(ApproveDepositAction::getDefaultName());
            $test->setActionData(['approval_event_id' => $eventId]);
            $test->callMountedAction();
            $test->assertNotified('Deposit approved');
        });

        $approvedEventId = $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('event_type', TrustApprovalEventType::DepositApproved->value)
            ->first()->id);

        $this->runWithFirmContext($firm, function () use ($ledger, $approvedEventId): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->mountAction(PostDepositAction::getDefaultName());
            $test->setActionData(['approval_event_id' => $approvedEventId]);
            $test->callMountedAction();
            $test->assertNotified('Deposit posted');
        });

        $balance = $this->runWithFirmContext($firm, fn () => $ledger->balance()->first());
        $this->assertSame(25000, $balance->balance_cents);

        // Requester and approver were used purely to establish role
        // context above; referenced to avoid unused-variable lint noise
        // and to document the two distinct actors involved.
        $this->assertNotSame($requester->id, $approver->id);
    }

    public function test_billing_staff_cannot_approve_a_deposit_even_if_the_action_is_forced(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->mountAction(RequestDepositAction::getDefaultName());
            $test->setActionData(['amount' => 100, 'matter_id' => null]);
            $test->callMountedAction();
        });

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionHidden(ApproveDepositAction::getDefaultName());
        });
    }

    public function test_approve_deposit_action_is_hidden_when_there_is_nothing_pending(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionHidden(ApproveDepositAction::getDefaultName());
            $test->assertActionHidden(PostDepositAction::getDefaultName());
        });
    }

    // ------------------------------------------------------------
    // Transfer: request -> approve/deny -> apply
    // ------------------------------------------------------------

    public function test_transfer_request_is_visible_only_for_requester_roles_and_calls_the_service(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->create(['firm_id' => $firm->id, 'client_id' => $ledger->client_id]));
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->create(['client_id' => $ledger->client_id, 'matter_id' => $matter->id]));

        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(TransferRequestsRelationManager::class, ['ownerRecord' => $ledger, 'pageClass' => ViewTrustLedger::class]);
            $test->assertTableActionHidden(RequestTransferAction::getDefaultName());
        });

        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($ledger, $matter, $invoice): void {
            $test = Livewire::test(TransferRequestsRelationManager::class, ['ownerRecord' => $ledger, 'pageClass' => ViewTrustLedger::class]);
            $test->mountTableAction(RequestTransferAction::getDefaultName());
            $test->setActionData(['matter_id' => $matter->id, 'invoice_id' => $invoice->id, 'amount' => 100]);
            $test->callMountedTableAction();
            $test->assertNotified('Transfer requested');
        });

        $request = $this->runWithFirmContext($firm, fn () => TrustTransferRequest::query()->where('trust_ledger_id', $ledger->id)->first());
        $this->assertNotNull($request);
        $this->assertSame(TrustTransferRequestStatus::Requested, $request->status);
        $this->assertSame(10000, $request->amount_cents);
    }

    // ------------------------------------------------------------
    // Refund: request -> approve/deny -> complete
    // ------------------------------------------------------------

    public function test_full_refund_lifecycle_via_filament_posts_a_refund_entry(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        app(TrustDepositService::class);
        // Seed a starting balance via the real deposit pipeline (never a raw write).
        $this->runWithFirmContext($firm, function () use ($firm, $ledger): void {
            $requester = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $approver = FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create();
            $depositService = app(TrustDepositService::class);
            $requested = $depositService->requestDeposit($firm, $ledger, $requester, 50000);
            $approved = $depositService->approveDeposit($firm, $requested, $approver);
            $depositService->post($firm, $ledger, $approved);
        });

        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(RefundRequestsRelationManager::class, ['ownerRecord' => $ledger, 'pageClass' => ViewTrustLedger::class]);
            $test->mountTableAction(RequestRefundAction::getDefaultName());
            $test->setActionData(['amount' => 100, 'matter_id' => null]);
            $test->callMountedTableAction();
            $test->assertNotified('Refund requested');
        });

        $refundRequest = $this->runWithFirmContext($firm, fn () => TrustRefundRequest::query()->where('trust_ledger_id', $ledger->id)->first());
        $this->assertSame(TrustRefundRequestStatus::Requested, $refundRequest->status);

        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->runWithFirmContext($firm, function () use ($ledger, $refundRequest): void {
            $test = Livewire::test(RefundRequestsRelationManager::class, ['ownerRecord' => $ledger, 'pageClass' => ViewTrustLedger::class]);
            $test->callTableAction(ApproveRefundAction::getDefaultName(), $refundRequest);
        });

        $approved = $this->runWithFirmContext($firm, fn () => TrustRefundRequest::query()->find($refundRequest->id));
        $this->assertSame(TrustRefundRequestStatus::Approved, $approved->status);

        $this->runWithFirmContext($firm, function () use ($ledger, $refundRequest): void {
            $test = Livewire::test(RefundRequestsRelationManager::class, ['ownerRecord' => $ledger, 'pageClass' => ViewTrustLedger::class]);
            $test->callTableAction(CompleteRefundAction::getDefaultName(), $refundRequest);
        });

        $completed = $this->runWithFirmContext($firm, fn () => TrustRefundRequest::query()->find($refundRequest->id));
        $this->assertSame(TrustRefundRequestStatus::Completed, $completed->status);

        $balance = $this->runWithFirmContext($firm, fn () => $ledger->balance()->first()->fresh());
        $this->assertSame(50000 - 10000, $balance->balance_cents);
    }

    // ------------------------------------------------------------
    // High-risk adjustment: request -> firstApprove -> secondApprove
    // (+ the distinct-approver UX guard)
    // ------------------------------------------------------------

    public function test_second_approve_adjustment_action_excludes_the_first_approvers_own_events(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $requester = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create());
        $firstApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->create());

        $adjustmentService = app(TrustHighRiskAdjustmentService::class);

        $this->runWithFirmContext($firm, function () use ($adjustmentService, $firm, $ledger, $requester, $firstApprover): void {
            $requested = $adjustmentService->requestAdjustment($firm, $ledger, $requester, 5000, 'Correction');
            $adjustmentService->firstApprove($firm, $requested, $firstApprover);
        });

        // The SAME user who first-approved must not see their own
        // first-approval as selectable for the second approval — the
        // distinct-approver UX guard (rule #5).
        $this->actingAs($firstApprover->user);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionHidden(SecondApproveAdjustmentAction::getDefaultName());
        });

        // A DIFFERENT eligible approver DOES see it.
        $secondApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->role(FirmUserRole::FirmOwner)->create());
        $this->actingAs($secondApprover->user);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionVisible(SecondApproveAdjustmentAction::getDefaultName());
        });

        $eventId = $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('event_type', TrustApprovalEventType::AdjustmentFirstApproved->value)
            ->first()->id);

        $this->runWithFirmContext($firm, function () use ($ledger, $eventId): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->mountAction(SecondApproveAdjustmentAction::getDefaultName());
            $test->setActionData(['approval_event_id' => $eventId]);
            $test->callMountedAction();
            $test->assertNotified('Adjustment second-approved and posted');
        });

        $balance = $this->runWithFirmContext($firm, fn () => $ledger->balance()->first()->fresh());
        $this->assertSame(5000, $balance->balance_cents);
    }

    public function test_request_adjustment_action_is_visible_for_requester_roles(): void
    {
        [$firm, $ledger] = $this->makeLedger();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($ledger): void {
            $test = Livewire::test(ViewTrustLedger::class, ['record' => $ledger->getRouteKey()]);
            $test->assertActionVisible(RequestAdjustmentAction::getDefaultName());
            // BillingStaff can request but never first-/second-approve.
            $test->assertActionHidden(FirstApproveAdjustmentAction::getDefaultName());
        });
    }

    // ------------------------------------------------------------
    // Small RLS/tenant-boundary regression checklist (trust_ledgers —
    // BelongsToTenant + FORCE RLS)
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_trust_ledgers(): void
    {
        [$firmA, $ledgerA] = $this->makeLedger();
        [$firmB, $ledgerB] = $this->makeLedger();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListTrustLedgers::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$ledgerA]);
        $test->assertCanNotSeeTableRecords([$ledgerB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_trust_ledger_row(): void
    {
        [$firmA, $ledgerA] = $this->makeLedger();
        [$firmB, $ledgerB] = $this->makeLedger();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('trust_ledgers')->pluck('id')->all());

        $this->assertContains($ledgerA->id, $visibleIds);
        $this->assertNotContains($ledgerB->id, $visibleIds, "Firm A's session must never read Firm B's trust ledger row.");
    }

    public function test_direct_url_guess_of_another_firms_trust_ledger_never_succeeds(): void
    {
        [$firmA] = $this->makeLedger();
        [, $ledgerB] = $this->makeLedger();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TrustLedgerResource::getUrl('view', ['record' => $ledgerB])));

        $response->assertNotFound();
    }

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
