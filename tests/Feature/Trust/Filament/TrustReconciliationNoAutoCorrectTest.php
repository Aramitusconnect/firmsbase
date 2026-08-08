<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use App\Enums\FirmUserRole;
use App\Enums\TrustReconciliationStatus;
use App\Filament\Firm\Resources\TrustAccountResource\Pages\ViewTrustAccount;
use App\Filament\Firm\Resources\TrustAccountResource\RelationManagers\ReconciliationsRelationManager;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustReconciliation;
use App\Models\User;
use App\Services\TrustAccountService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * TrustReconciliationNoAutoCorrectTest — proves StartReconciliationAction
 * really calls TrustReconciliationService::run() (state-verified, both
 * for a Balanced and a Discrepancy outcome), and — the specific,
 * mandatory safety proof this test exists for — that
 * ReconciliationsRelationManager NEVER offers any row/bulk action at
 * all: a Discrepancy result is displayed, full stop, never auto-
 * corrected (project rule).
 */
final class TrustReconciliationNoAutoCorrectTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_reconciliation_relation_manager_declares_zero_record_actions_and_zero_bulk_actions(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ReconciliationsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewTrustAccount::class]);
            $test->assertSuccessful();

            $table = $test->instance()->getTable();

            $this->assertSame(
                [],
                $table->getFlatRecordActions(),
                'ReconciliationsRelationManager must declare zero record actions — a Discrepancy result is only ever displayed, never auto-corrected.',
            );
            $this->assertSame(
                [],
                $table->getFlatBulkActions(),
                'ReconciliationsRelationManager must declare zero bulk actions.',
            );
        });
    }

    public function test_running_a_balanced_reconciliation_records_a_balanced_result_via_the_service(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ReconciliationsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewTrustAccount::class]);
            $test->mountTableAction('startReconciliation');
            $test->setActionData([
                'period_start' => now()->subDays(30)->toDateString(),
                'period_end' => now()->toDateString(),
                'asserted_bank_balance' => 0,
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Reconciliation complete — Balanced');
        });

        $reconciliation = $this->runWithFirmContext($firm, fn () => TrustReconciliation::query()->where('trust_account_id', $account->id)->first());
        $this->assertNotNull($reconciliation);
        $this->assertSame(TrustReconciliationStatus::Balanced, $reconciliation->status);
        $this->assertSame(0, $reconciliation->discrepancy_cents);
    }

    public function test_running_a_discrepancy_reconciliation_records_the_discrepancy_and_offers_no_fix(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ReconciliationsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewTrustAccount::class]);
            $test->mountTableAction('startReconciliation');
            $test->setActionData([
                'period_start' => now()->subDays(30)->toDateString(),
                'period_end' => now()->toDateString(),
                'asserted_bank_balance' => 500,
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Reconciliation complete — Discrepancy found');
        });

        $reconciliation = $this->runWithFirmContext($firm, fn () => TrustReconciliation::query()->where('trust_account_id', $account->id)->first());
        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(-50000, $reconciliation->discrepancy_cents);

        // Re-confirm no correcting action exists on the resulting table row.
        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ReconciliationsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewTrustAccount::class]);
            $table = $test->instance()->getTable();
            $this->assertSame([], $table->getFlatRecordActions());
        });
    }

    public function test_billing_staff_cannot_start_a_reconciliation(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->runWithFirmContext($firm, fn () => app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account'));
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, function () use ($account): void {
            $test = Livewire::test(ReconciliationsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewTrustAccount::class]);
            $test->assertTableActionHidden('startReconciliation');
        });

        $count = $this->runWithFirmContext($firm, fn () => TrustReconciliation::query()->where('trust_account_id', $account->id)->count());
        $this->assertSame(0, $count);
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
