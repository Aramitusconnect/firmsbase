<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Filament;

use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Filament\Firm\Pages\AccountingOverviewPage;
use App\Filament\Firm\Pages\AccountingOverviewPage\Actions\ClosePeriodAction;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AccountingOverviewPageTest — a real Livewire boot/registration smoke
 * test (no live browser available in this environment) proving the
 * page and its ClosePeriodAction actually work end-to-end, not just
 * that the PHP is syntactically valid.
 */
final class AccountingOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        // Non-Payment Completion Program, Workstream 7: FirmOwner/Attorney
        // now require confirmed 2FA regardless of the firm's own
        // firm_user_2fa_mode (see FirmUser2faPolicyService's platform-
        // minimum floor). This file's tests are about accounting page
        // access, not MFA, so the fixture is pre-confirmed here to stay
        // unaffected.
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create(['two_factor_confirmed_at' => now()]))->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    public function test_the_page_renders_successfully_for_a_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(AccountingOverviewPage::class);
            $test->assertSuccessful();
        });
    }

    public function test_closing_a_period_via_the_action_creates_a_closed_accounting_period(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $test = Livewire::test(AccountingOverviewPage::class);
            $test->mountAction(ClosePeriodAction::getDefaultName());
            $test->setActionData([
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ]);
            $test->callMountedAction();
            $test->assertNotified('Period closed');

            $period = AccountingPeriod::query()->where('firm_id', $firm->id)->first();
            $this->assertNotNull($period);
            $this->assertSame(AccountingPeriodStatus::Closed, $period->status);
        });
    }

    public function test_a_non_approver_role_cannot_access_the_page_at_all(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AccountingOverviewPage::getUrl()));

        $response->assertForbidden();
    }

    /**
     * Trust & Accounting Integrity Hardening, Mission 1.4: a firm with
     * no Chart of Accounts configured must see, on the page real
     * posting code actually gates on, exactly which required purposes
     * are missing.
     */
    public function test_the_page_lists_missing_required_chart_of_accounts_purposes(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(AccountingOverviewPage::class);
            $test->assertSuccessful();
            $test->assertSeeText('Missing required accounts for');
            $test->assertSeeText('Operating Cash');
        });
    }

    /**
     * RPT-001: the bucketed AR aging data AccountingReportingService::
     * accountsReceivableAging() already computes was previously only
     * collapsed into a single summary sentence — this proves the new
     * per-invoice table Section actually renders the real invoice,
     * client, remaining amount, days-overdue, and bucket for each row.
     */
    public function test_the_page_renders_a_bucketed_ar_aging_table_with_real_rows(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            $overdueInvoice = Invoice::factory()->forFirm($firm)->create([
                'status' => InvoiceStatus::Sent,
                'subtotal_cents' => 50000,
                'total_cents' => 50000,
                'amount_paid_cents' => 20000,
                'due_at' => now()->subDays(45),
            ]);

            $currentInvoice = Invoice::factory()->forFirm($firm)->create([
                'status' => InvoiceStatus::Sent,
                'subtotal_cents' => 10000,
                'total_cents' => 10000,
                'amount_paid_cents' => 0,
                'due_at' => now()->addDays(10),
            ]);

            $test = Livewire::test(AccountingOverviewPage::class);
            $test->assertSuccessful();

            // Remaining balances: overdue invoice $500.00 - $200.00 =
            // $300.00; current invoice $100.00 - $0.00 = $100.00.
            $test->assertSeeText('#'.$overdueInvoice->id);
            $test->assertSeeText($overdueInvoice->client->display_name);
            $test->assertSeeText('$300.00');
            $test->assertSeeText('31 60');

            $test->assertSeeText('#'.$currentInvoice->id);
            $test->assertSeeText($currentInvoice->client->display_name);
            $test->assertSeeText('$100.00');
            $test->assertSeeText('Current');
        });
    }

    public function test_the_page_confirms_all_required_purposes_once_configured(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $requiredPurposes = [
            ChartOfAccountPurpose::OperatingCash,
            ChartOfAccountPurpose::LegalFeeRevenue,
            ChartOfAccountPurpose::CostReimbursementRevenue,
            ChartOfAccountPurpose::GeneralOperatingExpense,
            ChartOfAccountPurpose::UnappliedOperatingFundsLiability,
            ChartOfAccountPurpose::OpeningBalanceEquity,
        ];

        $this->runWithFirmContext($firm, function () use ($firm, $requiredPurposes): void {
            foreach ($requiredPurposes as $index => $purpose) {
                ChartOfAccount::factory()->create([
                    'firm_id' => $firm->id,
                    'account_code' => (string) (1000 + $index),
                    'account_name' => $purpose->value,
                    'account_type' => ChartOfAccountType::Asset,
                    'purpose' => $purpose,
                    'is_active' => true,
                ]);
            }

            $test = Livewire::test(AccountingOverviewPage::class);
            $test->assertSuccessful();
            $test->assertSeeText('All required accounting purposes are configured.');
        });
    }
}
