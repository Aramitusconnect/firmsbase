<?php

namespace Tests\Feature\Accounting\ChartOfAccounts;

use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ChartOfAccountsService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    public function test_chart_of_account_can_be_created(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        $account = $this->service->create($firm, '6000', 'Office Supplies', ChartOfAccountType::Expense);

        // chart_of_accounts now has permanent FORCE ROW LEVEL SECURITY
        // (see database/migrations/2026_08_27_950018_prepare_row_level_
        // security_and_force_rls_on_chart_of_accounts_table.php).
        // assertDatabaseHas() queries with no tenant context of its own,
        // so it would (incorrectly) see zero rows against this now-forced
        // table unless wrapped — matching this project's established
        // convention (see e.g. MatterExpenseServiceTest).
        $this->runWithFirmContext($firm, function () use ($account, $firm) {
            $this->assertDatabaseHas('chart_of_accounts', ['id' => $account->id, 'firm_id' => $firm->id, 'account_code' => '6000']);
        });
    }

    public function test_creation_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, '6000', 'Office Supplies', ChartOfAccountType::Expense);
    }

    /**
     * Correction #4: no starter/default COA seed data anywhere in
     * Phase 12 — a freshly-migrated firm has zero chart_of_accounts
     * rows until it creates its own.
     */
    public function test_no_starter_or_default_chart_of_accounts_rows_exist(): void
    {
        Firm::factory()->create();

        $this->assertSame(0, ChartOfAccount::query()->count());
    }
}
