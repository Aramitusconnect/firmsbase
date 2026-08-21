<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\ChartOfAccounts;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ChartOfAccountResource;
use App\Filament\Firm\Resources\ChartOfAccountResource\Actions\DeactivateChartOfAccountAction;
use App\Filament\Firm\Resources\ChartOfAccountResource\Pages\CreateChartOfAccount;
use App\Filament\Firm\Resources\ChartOfAccountResource\Pages\ListChartOfAccounts;
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
 * ChartOfAccountResourceTest — Trust & Accounting Integrity Hardening,
 * Mission 1.4: the first Firm-facing UI for chart_of_accounts. Proves
 * role gating (mirrors ExpenseCategoryResourceTest's exact shape),
 * real service-mediated create/deactivate (never a bare Eloquent
 * write), the partial-unique-index-on-purpose violation surfacing as a
 * normal form error rather than a 500, and tenant isolation.
 */
final class ChartOfAccountResourceTest extends TestCase
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

    public function test_firm_owner_and_billing_staff_can_access_but_attorney_cannot(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $this->assertTrue(ChartOfAccountResource::canAccess());

        $firmB = $this->expenseEntitledFirm();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertTrue(ChartOfAccountResource::canAccess());

        $firmC = $this->expenseEntitledFirm();
        $this->actingAsRole($firmC, FirmUserRole::Attorney);
        $this->assertFalse(ChartOfAccountResource::canAccess());
    }

    public function test_resource_is_hidden_when_the_firm_lacks_the_expenses_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(ChartOfAccountResource::canAccess());
    }

    public function test_create_persists_via_chart_of_accounts_service_with_a_purpose(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = Livewire::test(CreateChartOfAccount::class);
        $test->fillForm([
            'account_code' => '1000',
            'account_name' => 'Operating Cash',
            'account_type' => ChartOfAccountType::Asset->value,
            'purpose' => ChartOfAccountPurpose::OperatingCash->value,
        ]);
        $test->call('create');

        $test->assertHasNoFormErrors();
        $this->runWithFirmContext($firm, function () use ($firm): void {
            $account = ChartOfAccount::query()->where('firm_id', $firm->id)->where('account_code', '1000')->first();
            $this->assertNotNull($account);
            $this->assertSame(ChartOfAccountType::Asset, $account->account_type);
            $this->assertSame(ChartOfAccountPurpose::OperatingCash, $account->purpose);
            $this->assertTrue($account->is_active);
        });
    }

    public function test_create_persists_without_a_purpose(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = Livewire::test(CreateChartOfAccount::class);
        $test->fillForm([
            'account_code' => '6000',
            'account_name' => 'Office Supplies',
            'account_type' => ChartOfAccountType::Expense->value,
        ]);
        $test->call('create');

        $test->assertHasNoFormErrors();
        $this->runWithFirmContext($firm, function () use ($firm): void {
            $account = ChartOfAccount::query()->where('firm_id', $firm->id)->where('account_code', '6000')->first();
            $this->assertNotNull($account);
            $this->assertNull($account->purpose);
        });
    }

    /**
     * chart_of_accounts_firm_active_purpose_unique (a pre-existing
     * partial unique index, not introduced by this mission) must reject
     * a second active account claiming a purpose already held — and
     * this page must surface that as a normal field error, not a 500.
     */
    public function test_a_second_active_account_cannot_claim_a_purpose_already_held(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->create([
            'firm_id' => $firm->id,
            'account_code' => '1000',
            'account_name' => 'Operating Cash',
            'account_type' => ChartOfAccountType::Asset,
            'purpose' => ChartOfAccountPurpose::OperatingCash,
            'is_active' => true,
        ]));

        $test = Livewire::test(CreateChartOfAccount::class);
        $test->fillForm([
            'account_code' => '1001',
            'account_name' => 'Second Cash Account',
            'account_type' => ChartOfAccountType::Asset->value,
            'purpose' => ChartOfAccountPurpose::OperatingCash->value,
        ]);
        $test->call('create');

        $test->assertHasFormErrors(['purpose']);
        $this->runWithFirmContext($firm, function () use ($firm): void {
            $this->assertSame(1, ChartOfAccount::query()->where('firm_id', $firm->id)->where('purpose', ChartOfAccountPurpose::OperatingCash->value)->count());
        });
    }

    public function test_deactivate_removes_the_account_from_active_use_without_deleting_it(): void
    {
        $firm = $this->expenseEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $account = $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->create([
            'firm_id' => $firm->id,
            'is_active' => true,
        ]));

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ListChartOfAccounts::class);
            $test->mountTableAction(DeactivateChartOfAccountAction::getDefaultName(), $account->getKey());
            $test->callMountedTableAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => ChartOfAccount::query()->find($account->id));
        $this->assertNotNull($fresh, 'Deactivation must never hard-delete the row.');
        $this->assertFalse($fresh->is_active);
    }

    public function test_list_page_shows_only_this_firms_accounts(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $accountA = $this->runWithFirmContext($firmA, fn () => ChartOfAccount::factory()->create(['firm_id' => $firmA->id]));
        $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->create(['firm_id' => $firmB->id]));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListChartOfAccounts::class));
        $test->assertCanSeeTableRecords([$accountA]);
    }

    public function test_direct_url_guess_of_another_firms_account_never_succeeds(): void
    {
        $firmA = $this->expenseEntitledFirm();
        $firmB = $this->expenseEntitledFirm();
        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->create(['firm_id' => $firmB->id]));

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ChartOfAccountResource::getUrl('view', ['record' => $accountB->getRouteKey()])));

        $this->assertNotSame(200, $response->getStatusCode());
    }
}
