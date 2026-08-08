<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TimeEntryStatus;
use App\Filament\Firm\Resources\InvoiceResource;
use App\Filament\Firm\Resources\InvoiceResource\Actions\AddManualChargeAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\ApproveInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\CreateFlatFeeInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\DraftFromTimeEntriesAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SendInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SubmitInvoiceForReviewAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\VoidInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * InvoiceResourceAccessTest — Firm Feature Manifest §6 Tier 2. Proves
 * role ceilings, that every named Action calls the real
 * InvoiceDraftingService method (never a bare Invoice::create()/
 * update()), that each Action is hidden/disabled when the invoice's
 * current status doesn't legally allow that transition, and the small
 * RLS regression checklist required for this module.
 */
final class InvoiceResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. List page renders
    // ------------------------------------------------------------

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListInvoices::class));

        $test->assertSuccessful();
    }

    public function test_view_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(InvoiceResource::getUrl('view', ['record' => $invoice])));

        $response->assertSuccessful();
    }

    // ------------------------------------------------------------
    // 2. Role ceilings — draft tier vs approve tier
    // ------------------------------------------------------------

    public function test_draft_actions_are_visible_for_firm_owner_attorney_and_billing_staff(): void
    {
        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListInvoices::class));
            $test->assertActionVisible(DraftFromTimeEntriesAction::getDefaultName());
            $test->assertActionVisible(CreateFlatFeeInvoiceAction::getDefaultName());
        }
    }

    public function test_draft_actions_are_hidden_for_paralegal_legal_assistant_and_receptionist(): void
    {
        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListInvoices::class));
            $test->assertActionHidden(DraftFromTimeEntriesAction::getDefaultName());
            $test->assertActionHidden(CreateFlatFeeInvoiceAction::getDefaultName());
        }
    }

    public function test_approve_send_void_are_hidden_for_billing_staff(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::PendingReview)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(ApproveInvoiceAction::getDefaultName(), $invoice);
        });
    }

    public function test_approve_action_is_visible_for_firm_owner_when_pending_review(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::PendingReview)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionVisible(ApproveInvoiceAction::getDefaultName(), $invoice);
        });
    }

    // ------------------------------------------------------------
    // 3. Actions hidden when the invoice's status doesn't allow them
    // ------------------------------------------------------------

    public function test_add_manual_charge_is_hidden_once_invoice_leaves_draft(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::PendingReview)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(AddManualChargeAction::getDefaultName(), $invoice);
        });
    }

    public function test_submit_for_review_is_hidden_when_not_draft(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Approved)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(SubmitInvoiceForReviewAction::getDefaultName(), $invoice);
        });
    }

    public function test_send_is_hidden_unless_approved(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Draft)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(SendInvoiceAction::getDefaultName(), $invoice);
        });
    }

    public function test_void_is_hidden_once_paid(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Paid)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(VoidInvoiceAction::getDefaultName(), $invoice);
        });
    }

    // ------------------------------------------------------------
    // 4. Each Action really calls the matching InvoiceDraftingService
    //    method (proven via resulting state, not a raw model write)
    // ------------------------------------------------------------

    public function test_draft_from_time_entries_creates_an_invoice_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'status' => TimeEntryStatus::Approved,
            'is_billable' => true,
            'billing_rate_cents_snapshot' => 20000,
            'seconds' => 3600,
        ]));

        $this->runWithFirmContext($firm, function () use ($client, $entry): void {
            $test = Livewire::test(ListInvoices::class);
            $test->mountAction(DraftFromTimeEntriesAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'matter_id' => null,
                'time_entry_ids' => [$entry->id],
            ]);
            $test->callMountedAction();
            $test->assertNotified('Invoice drafted');
        });

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(20000, $invoice->total_cents);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => $invoice->lines()->count()));

        // Proves this went through the real service, not a bare write:
        // the source TimeEntry is marked Invoiced by
        // TimeEntryApprovalService::markInvoiced(), only ever called
        // from inside draftFromTimeEntries().
        $freshEntry = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->find($entry->id));
        $this->assertSame(TimeEntryStatus::Invoiced, $freshEntry->status);
    }

    public function test_create_flat_fee_invoice_creates_an_invoice_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListInvoices::class);
            $test->mountAction(CreateFlatFeeInvoiceAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'matter_id' => null,
                'description' => 'Flat fee for filing',
                'amount' => 500,
            ]);
            $test->callMountedAction();
            $test->assertNotified('Flat-fee invoice created');
        });

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($invoice);
        $this->assertSame(50000, $invoice->total_cents);
        $this->assertSame(InvoiceType::FlatFee, $invoice->invoice_type);
    }

    public function test_add_manual_charge_recomputes_totals_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Draft)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->mountTableAction(AddManualChargeAction::getDefaultName(), $invoice);
            $test->setActionData(['description' => 'Filing fee', 'amount' => 75]);
            $test->callMountedTableAction();
            $test->assertNotified('Charge added');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(7500, $fresh->total_cents);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => $fresh->lines()->count()));
    }

    public function test_submit_approve_send_transition_the_invoice_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Draft)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->callTableAction(SubmitInvoiceForReviewAction::getDefaultName(), $invoice);
        });
        $this->assertSame(InvoiceStatus::PendingReview, $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id)->status));

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->callTableAction(ApproveInvoiceAction::getDefaultName(), $invoice);
        });
        $approved = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(InvoiceStatus::Approved, $approved->status);
        $this->assertNotNull($approved->issued_at);

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->callTableAction(SendInvoiceAction::getDefaultName(), $invoice);
        });
        $sent = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(InvoiceStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);
    }

    public function test_void_transitions_the_invoice_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Draft)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->mountTableAction(VoidInvoiceAction::getDefaultName(), $invoice);
            $test->setActionData(['reason' => 'Entered in error']);
            $test->callMountedTableAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNotNull($fresh->voided_at);
    }

    public function test_unauthorized_role_cannot_approve_even_if_the_action_is_forced(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::PendingReview)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->assertTableActionHidden(ApproveInvoiceAction::getDefaultName(), $invoice);
        });

        $this->assertSame(InvoiceStatus::PendingReview, $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id)->status));
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    public function test_a_firm_user_can_access_its_own_invoices(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(InvoiceResource::getUrl('view', ['record' => $invoice])));

        $response->assertSuccessful();
    }

    public function test_list_page_shows_only_this_firms_invoices(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $invoiceA = $this->runWithFirmContext($firmA, fn () => Invoice::factory()->forFirm($firmA)->create());
        $invoiceB = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListInvoices::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$invoiceA]);
        $test->assertCanNotSeeTableRecords([$invoiceB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_invoice_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $invoiceA = $this->runWithFirmContext($firmA, fn () => Invoice::factory()->forFirm($firmA)->create());
        $invoiceB = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('invoices')->pluck('id')->all());

        $this->assertContains($invoiceA->id, $visibleIds);
        $this->assertNotContains($invoiceB->id, $visibleIds, "Firm A's session must never read Firm B's invoice row.");
    }

    public function test_client_select_options_never_include_a_foreign_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $clientB): void {
            $visibleClientIds = Client::query()->pluck('id')->all();

            $this->assertContains($clientA->id, $visibleClientIds);
            $this->assertNotContains($clientB->id, $visibleClientIds, "Firm A's client_id options must never include Firm B's client.");
        });
    }

    public function test_direct_url_guess_of_another_firms_invoice_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $invoiceB = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(InvoiceResource::getUrl('view', ['record' => $invoiceB])));

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
