<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Filament;

use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\AccountingOverviewPage;
use App\Filament\Firm\Pages\AccountingOverviewPage\Actions\ClosePeriodAction;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
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
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
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
