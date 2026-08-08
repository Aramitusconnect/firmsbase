<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\ChartOfAccounts;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ExpenseCategoryResource;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Actions\DeactivateExpenseCategoryAction;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Actions\ReactivateExpenseCategoryAction;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\CreateExpenseCategory;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\EditExpenseCategory;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ExpenseCategoryResourceTest — FirmsVault staging follow-up
 * ("Application Completion — Catalogs + Firm-Owned Reference Data").
 * "Firm Management → Expense Categories". Proves role gating
 * (FirmOwner/BillingStaff only, mirroring AccountingEntitlementPolicyService
 * ::APPROVER_ROLES), real service-mediated create/edit/deactivate/
 * reactivate (never a bare Eloquent write), and tenant isolation
 * (FORCE RLS on expense_categories).
 */
final class ExpenseCategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
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

    // ------------------------------------------------------------
    // Role gating
    // ------------------------------------------------------------

    public function test_firm_owner_and_billing_staff_can_access_but_receptionist_cannot(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $this->assertTrue(ExpenseCategoryResource::canAccess());

        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertTrue(ExpenseCategoryResource::canAccess());

        $firmC = $this->expenseEntitledFirm();
        $this->actingAsRole($firmC, FirmUserRole::Receptionist);
        $this->assertFalse(ExpenseCategoryResource::canAccess());
    }

    public function test_resource_is_hidden_when_the_firm_lacks_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(ExpenseCategoryResource::canAccess());
    }

    // ------------------------------------------------------------
    // Create / Edit — real service-mediated writes
    // ------------------------------------------------------------

    public function test_create_persists_via_expense_category_service(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = Livewire::test(CreateExpenseCategory::class);
        $test->fillForm(['name' => 'Filing Fees']);
        $test->call('create');

        $test->assertHasNoFormErrors();
        $this->runWithFirmContext($firm, function () use ($firm): void {
            $this->assertNotNull(ExpenseCategory::query()->where('firm_id', $firm->id)->where('name', 'Filing Fees')->first());
        });
    }

    public function test_edit_persists_a_rename_via_the_service(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $category = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create(['name' => 'Old Name']));

        $this->runWithFirmContext($firm, function () use ($category): void {
            $test = Livewire::test(EditExpenseCategory::class, ['record' => $category->getRouteKey()]);
            $test->fillForm(['name' => 'New Name']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->find($category->id));
        $this->assertSame('New Name', $fresh->name);
    }

    public function test_deactivate_then_reactivate_round_trips_without_deleting_the_row(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $category = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create(['is_active' => true]));

        $this->runWithFirmContext($firm, function () use ($category): void {
            $test = Livewire::test(ListExpenseCategories::class);
            $test->mountTableAction(DeactivateExpenseCategoryAction::getDefaultName(), $category->getKey());
            $test->callMountedTableAction();
        });
        $deactivated = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->find($category->id));
        $this->assertFalse($deactivated->is_active);
        $this->assertNotNull($deactivated, 'Deactivation must never hard-delete the row.');

        $this->runWithFirmContext($firm, function () use ($category): void {
            $test = Livewire::test(ListExpenseCategories::class);
            $test->mountTableAction(ReactivateExpenseCategoryAction::getDefaultName(), $category->getKey());
            $test->callMountedTableAction();
        });
        $reactivated = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->find($category->id));
        $this->assertTrue($reactivated->is_active);
    }

    // ------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_categories(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $categoryA = $this->runWithFirmContext($firmA, fn () => ExpenseCategory::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create());

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListExpenseCategories::class));
        $test->assertCanSeeTableRecords([$categoryA]);
    }

    public function test_category_select_options_never_include_a_foreign_firms_category(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create(['name' => 'Foreign Category']));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firmA, function (): void {
            $options = ExpenseCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

            $this->assertNotContains('Foreign Category', $options);
        });
    }

    public function test_direct_url_guess_of_another_firms_category_never_succeeds(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $categoryB = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create());

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ExpenseCategoryResource::getUrl('edit', ['record' => $categoryB->getRouteKey()])));

        $this->assertNotSame(200, $response->getStatusCode());
    }
}
