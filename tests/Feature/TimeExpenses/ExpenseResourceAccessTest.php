<?php

declare(strict_types=1);

namespace Tests\Feature\TimeExpenses;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ExpenseResource;
use App\Filament\Firm\Resources\ExpenseResource\Actions\ApproveExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\RejectExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\SubmitExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\VoidExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Firm\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Firm\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ExpenseResourceAccessTest — Firm Feature Manifest §6 (Tier1-C).
 * Proves entitlement gating (the `expenses` module_catalog code — the
 * one real difference from the Time Entry cluster), role ceilings, real
 * service-mediated create/edit (ExpenseService), Submit/Approve/Reject/
 * Void row actions (ExpenseService/ExpenseApprovalService), and the
 * small RLS regression checklist required for this module.
 */
final class ExpenseResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. Entitlement gating — the real difference from Time Entries
    // ------------------------------------------------------------

    public function test_resource_is_hidden_when_the_firm_lacks_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(ExpenseResource::canAccess());
        $this->assertFalse(ExpenseResource::shouldRegisterNavigation());
    }

    public function test_resource_is_visible_once_the_firm_is_entitled(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertTrue(ExpenseResource::canAccess());
        $this->assertTrue(ExpenseResource::shouldRegisterNavigation());
    }

    public function test_direct_route_hit_is_blocked_when_the_firm_lacks_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ExpenseResource::getUrl('index')));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ------------------------------------------------------------
    // 2. Role ceilings (entitled firm)
    // ------------------------------------------------------------

    public function test_billing_staff_can_create_an_expense_but_receptionist_cannot(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::BillingStaff);
        $this->assertTrue(ExpenseResource::canCreate());

        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmB, FirmUserRole::Receptionist);
        $this->assertFalse(ExpenseResource::canCreate());
    }

    // ------------------------------------------------------------
    // 3. Create/Edit — real service-mediated writes
    // ------------------------------------------------------------

    public function test_create_expense_persists_via_expense_service_as_a_draft(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $category = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create(['name' => 'Filing Fees']));

        $this->runWithFirmContext($firm, function () use ($category): void {
            $test = Livewire::test(CreateExpense::class);
            $test->fillForm([
                'vendor_name' => 'County Clerk',
                'amount' => 125.50,
                'expense_category_id' => $category->id,
                'expense_date' => now()->toDateString(),
                'reimbursable' => true,
                'description' => 'Filing fee for motion',
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $expense = $this->runWithFirmContext($firm, fn () => Expense::query()->where('vendor_name', 'County Clerk')->first());
        $this->assertNotNull($expense);
        $this->assertSame((int) $firm->id, (int) $expense->firm_id);
        $this->assertSame(12550, $expense->amount_cents);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertTrue($expense->reimbursable);
    }

    public function test_edit_expense_persists_a_change_via_expense_service_while_draft(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create([
            'status' => ExpenseStatus::Draft,
            'vendor_name' => 'Original Vendor',
        ]));

        $this->runWithFirmContext($firm, function () use ($expense): void {
            $test = Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()]);
            $test->fillForm(['vendor_name' => 'Updated Vendor']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Expense::query()->find($expense->id));
        $this->assertSame('Updated Vendor', $fresh->vendor_name);
    }

    public function test_edit_page_is_not_authorized_for_a_submitted_expense(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['status' => ExpenseStatus::Submitted]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ExpenseResource::getUrl('edit', ['record' => $expense])));

        $response->assertForbidden();
    }

    public function test_expense_form_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/ExpenseResource.php'));
        $this->assertIsString($source);

        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringNotContainsString("make('status')", $matches[0]);
    }

    // ------------------------------------------------------------
    // 4. Submit / Approve / Reject / Void row actions
    // ------------------------------------------------------------

    public function test_submit_action_transitions_a_draft_expense_to_submitted(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['status' => ExpenseStatus::Draft]));

        $this->runWithFirmContext($firm, function () use ($expense): void {
            $test = Livewire::test(ListExpenses::class);
            $test->callTableAction(SubmitExpenseAction::getDefaultName(), $expense);
            $test->assertNotified('Expense submitted');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Expense::query()->find($expense->id));
        $this->assertSame(ExpenseStatus::Submitted, $fresh->status);
    }

    public function test_approve_action_hidden_for_attorney_and_visible_for_billing_staff(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::Attorney);
        $expenseA = $this->runWithFirmContext($firmA, fn () => Expense::factory()->forFirm($firmA)->create(['status' => ExpenseStatus::Submitted]));

        $this->runWithFirmContext($firmA, function () use ($expenseA): void {
            $test = Livewire::test(ListExpenses::class);
            $test->assertTableActionHidden(ApproveExpenseAction::getDefaultName(), $expenseA);
        });

        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $expenseB = $this->runWithFirmContext($firmB, fn () => Expense::factory()->forFirm($firmB)->create(['status' => ExpenseStatus::Submitted]));

        $this->runWithFirmContext($firmB, function () use ($expenseB): void {
            $test = Livewire::test(ListExpenses::class);
            $test->assertTableActionVisible(ApproveExpenseAction::getDefaultName(), $expenseB);
        });
    }

    public function test_approve_action_approves_a_submitted_expense(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['status' => ExpenseStatus::Submitted]));

        $this->runWithFirmContext($firm, function () use ($expense): void {
            $test = Livewire::test(ListExpenses::class);
            $test->callTableAction(ApproveExpenseAction::getDefaultName(), $expense);
            $test->assertNotified('Expense approved');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Expense::query()->find($expense->id));
        $this->assertSame(ExpenseStatus::Approved, $fresh->status);
    }

    public function test_reject_action_requires_a_reason_and_rejects_the_expense(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['status' => ExpenseStatus::Submitted]));

        $this->runWithFirmContext($firm, function () use ($expense): void {
            $test = Livewire::test(ListExpenses::class);
            $test->mountTableAction(RejectExpenseAction::getDefaultName(), $expense->id);
            $test->setActionData(['reason' => 'Missing receipt']);
            $test->callMountedTableAction();
            $test->assertNotified('Expense rejected');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Expense::query()->find($expense->id));
        $this->assertSame(ExpenseStatus::Rejected, $fresh->status);
    }

    public function test_void_action_voids_a_draft_expense(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['status' => ExpenseStatus::Draft]));

        $this->runWithFirmContext($firm, function () use ($expense): void {
            $test = Livewire::test(ListExpenses::class);
            $test->callTableAction(VoidExpenseAction::getDefaultName(), $expense);
            $test->assertNotified('Expense voided');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Expense::query()->find($expense->id));
        $this->assertSame(ExpenseStatus::Voided, $fresh->status);
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own Expense records. */
    public function test_a_firm_user_can_access_its_own_expenses(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ExpenseResource::getUrl('view', ['record' => $expense])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's Expense is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_expenses(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $expenseA = $this->runWithFirmContext($firmA, fn () => Expense::factory()->forFirm($firmA)->create());
        $expenseB = $this->runWithFirmContext($firmB, fn () => Expense::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListExpenses::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$expenseA]);
        $test->assertCanNotSeeTableRecords([$expenseB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_expense_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $expenseA = $this->runWithFirmContext($firmA, fn () => Expense::factory()->forFirm($firmA)->create());
        $expenseB = $this->runWithFirmContext($firmB, fn () => Expense::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('expenses')->pluck('id')->all());

        $this->assertContains($expenseA->id, $visibleIds);
        $this->assertNotContains($expenseB->id, $visibleIds, "Firm A's session must never read Firm B's expense row.");
    }

    /** (c) a foreign matter cannot be selected via the matter_id relation select. */
    public function test_matter_select_options_never_include_a_foreign_firms_matter(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::BillingStaff);
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ExpenseResource::getUrl('create')));
        $response->assertSuccessful();

        $this->runWithFirmContext($firmA, function () use ($matterA, $matterB): void {
            $visibleMatterIds = Matter::query()->pluck('id')->all();

            $this->assertContains($matterA->id, $visibleMatterIds);
            $this->assertNotContains($matterB->id, $visibleMatterIds, "Firm A's matter_id options must never include Firm B's matter.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_expense_never_succeeds(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $expenseB = $this->runWithFirmContext($firmB, fn () => Expense::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ExpenseResource::getUrl('view', ['record' => $expenseB])));

        $response->assertNotFound();
    }

    private function expenseEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        return $firm;
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
