<?php

declare(strict_types=1);

namespace Tests\Feature\TimeExpenses;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\ExpenseReportPage;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ExpenseReportPageTest — Firm Feature Manifest §9. Proves the page is
 * entitlement-gated like ExpenseResource, that it renders correct
 * totals/filtering against the REAL ExpenseReportingService::list()/
 * totalAmountCents() (never a hand-rolled duplicate query), that a
 * foreign firm's expenses never leak into either the list or the
 * total, and that matter/reimbursable filters narrow both consistently.
 */
final class ExpenseReportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_page_is_hidden_without_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(ExpenseReportPage::canAccess());
    }

    public function test_page_is_visible_once_the_firm_is_entitled(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertTrue(ExpenseReportPage::canAccess());
    }

    public function test_report_lists_only_this_firms_expenses_and_computes_the_correct_total(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $expenseA1 = $this->runWithFirmContext($firmA, fn () => Expense::factory()->forFirm($firmA)->create(['amount_cents' => 10000]));
        $expenseA2 = $this->runWithFirmContext($firmA, fn () => Expense::factory()->forFirm($firmA)->create(['amount_cents' => 5000]));
        $expenseB = $this->runWithFirmContext($firmB, fn () => Expense::factory()->forFirm($firmB)->create(['amount_cents' => 999999]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ExpenseReportPage::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$expenseA1, $expenseA2]);
        $test->assertCanNotSeeTableRecords([$expenseB]);
        $test->assertSee('$150.00');
    }

    public function test_matter_filter_narrows_both_the_list_and_the_total(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $expenseOnA = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterA->id, 'amount_cents' => 7000]));
        $expenseOnB = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create(['matter_id' => $matterB->id, 'amount_cents' => 3000]));

        $this->runWithFirmContext($firm, function () use ($matterA, $expenseOnA, $expenseOnB): void {
            $test = Livewire::test(ExpenseReportPage::class);
            $test->set('data.matter_id', $matterA->id);
            $test->assertCanSeeTableRecords([$expenseOnA]);
            $test->assertCanNotSeeTableRecords([$expenseOnB]);
            $test->assertSee('$70.00');
        });
    }

    public function test_reimbursable_filter_narrows_both_the_list_and_the_total(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $reimbursable = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->reimbursable(true)->create(['amount_cents' => 4000]));
        $nonReimbursable = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->reimbursable(false)->create(['amount_cents' => 6000]));

        $this->runWithFirmContext($firm, function () use ($reimbursable, $nonReimbursable): void {
            $test = Livewire::test(ExpenseReportPage::class);
            $test->set('data.reimbursable', '1');
            $test->assertCanSeeTableRecords([$reimbursable]);
            $test->assertCanNotSeeTableRecords([$nonReimbursable]);
            $test->assertSee('$40.00');
        });
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
