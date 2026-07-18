<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\EntitlementSource;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\MatterExpenseService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterExpenseService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new MatterExpenseService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
        );
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    /** Required: matter expense link is same-firm only. */
    public function test_matter_expense_link_succeeds_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $link = $this->service->link($firm, $matter, $expense);

        // matter_expenses now has permanent FORCE ROW LEVEL SECURITY (see
        // database/migrations/2026_08_27_950012_prepare_row_level_
        // security_and_force_rls_on_matter_expenses_table.php).
        // assertDatabaseHas() queries with no tenant context of its own,
        // so it would (correctly) see zero rows against this now-forced
        // table — the re-read below is an explicit, context-wrapped read
        // instead, matching this project's established convention (see
        // e.g. FirmActivationEventsForceRlsActivationTest).
        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => \App\Models\MatterExpense::withoutGlobalScopes()->find($link->id),
        );

        $this->assertNotNull($reRead, 'link() must genuinely persist a matter_expenses row, readable under its own firm context.');
        $this->assertSame($matter->id, $reRead->matter_id);
        $this->assertSame($expense->id, $reRead->expense_id);
        $this->assertSame($firm->id, $reRead->firm_id);
    }

    /**
     * CRITICAL REGRESSION TEST (security review finding): wrapping the
     * duplicate-guard read (`$expense->matterExpense()->exists()`) and
     * the create() write together in one outer runWithFirmContext() call
     * must NOT silently defeat the pre-existing "already linked" guard.
     * Calling link() twice for the same expense must still throw.
     */
    public function test_linking_the_same_expense_twice_still_throws_the_duplicate_guard(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matterOne = Matter::factory()->forFirm($firm)->create();
        $matterTwo = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->service->link($firm, $matterOne, $expense);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This expense is already linked to a matter.');

        $this->service->link($firm, $matterTwo, $expense);
    }

    /** Tenant context must clear after both the success and exception paths through link(). */
    public function test_tenant_context_clears_after_link_success_and_after_duplicate_guard_exception(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        // MatterFactory/ExpenseFactory deliberately leave the PostgreSQL
        // session's database-only tenant context set to the fixture firm
        // afterward (their own established convention). Clear it
        // explicitly so the assertions below prove link() itself leaves
        // no context behind, rather than merely restoring that
        // pre-existing fixture leftover.
        (new \App\Services\TenantContextService())->clearDatabaseTenantContext();

        $this->service->link($firm, $matter, $expense);
        $this->assertNoDatabaseTenantContext('link() must clear its own internal context wrap after a successful link.');

        try {
            $this->service->link($firm, $matter, $expense);
            $this->fail('Expected the duplicate-guard RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext('link() must clear its own internal context wrap even when the duplicate guard throws.');
    }

    /** Required: cross-firm invoice/expense/matter combinations are blocked. */
    public function test_matter_expense_link_blocked_cross_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->enableExpenses($otherFirm);

        $matter = Matter::factory()->forFirm($otherFirm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->link($firm, $matter, $expense);
    }

    /** Required: reimbursable snapshot is preserved. */
    public function test_reimbursable_snapshot_is_preserved_after_expense_changes(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->create();

        $link = $this->service->link($firm, $matter, $expense);
        $this->assertTrue($link->reimbursable_snapshot);

        // Later, the expense's own reimbursable flag changes — the
        // snapshot on the already-created link must NOT change.
        // expenses now ALSO has permanent FORCE ROW LEVEL SECURITY (see
        // database/migrations/2026_08_27_950020_prepare_row_level_
        // security_and_force_rls_on_expenses_table.php, landing in the
        // same Wave 4 batch as this file's own regression fix), so this
        // update must run under the owning firm's explicit context —
        // it has none of its own here otherwise.
        $this->runWithFirmContext($firm, fn () => $expense->update(['reimbursable' => false]));

        // matter_expenses now has permanent FORCE ROW LEVEL SECURITY —
        // ->fresh() re-queries with no tenant context of its own (link()
        // already cleared its internal wrap), so re-read explicitly
        // under the owning firm's context instead.
        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => \App\Models\MatterExpense::withoutGlobalScopes()->find($link->id),
        );

        $this->assertNotNull($reRead);
        $this->assertTrue($reRead->reimbursable_snapshot);
    }

    public function test_linking_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->link($firm, $matter, $expense);
    }
}
