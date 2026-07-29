<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Livewire\FinancialEvidence\FinancialEvidenceOverviewPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceReviewQueuesPanel;
use App\Livewire\FinancialEvidence\FinancialEvidenceSummaryPanel;
use App\Livewire\FinancialEvidence\ReviewQueues\DuplicateTransfersQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\LargeDepositsQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\ReconciliationCandidatesQueuePanel;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceDuplicateTransferFlag;
use App\Models\FinancialEvidenceLargeDepositFlag;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceReconciliationCandidate;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\TimelineEvent;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * FinancialEvidencePanelFinancialTierAccessTest — C2 remediation proof.
 *
 * Six Financial Evidence Workspace panels (Overview, Summary, Review
 * Queues, and the three ReviewQueues/* sub-panels) were gated ONLY by
 * GatesFinancialEvidenceMatterAccess / MatterAccessPolicyService::
 * canAccessMatter(), which — per that service's own docblock — grants
 * Paralegal, LegalAssistant, Receptionist and BillingStaff access to
 * any matter they hold an ACTIVE MatterAssignment for. None of the six
 * called FinancialIntegrationAccessPolicyService, whose own docblock is
 * explicit that "Paralegal, LegalAssistant, and Receptionist NEVER
 * receive any financial-tier integration permission, full stop."
 *
 * Every role expectation below is derived from
 * FinancialIntegrationAccessPolicyService::canView()'s ACTUAL RETURN
 * VALUE at runtime — never from a hardcoded role-name list — so this
 * suite cannot silently drift from the authoritative policy if that
 * policy's own role tiers ever change.
 *
 * Note on the review's "ReadOnlyAuditor" row: FirmUserRole has exactly
 * six cases and no auditor case exists in this codebase
 * (test_the_role_enum_has_no_auditor_case_so_that_matrix_row_is_not_applicable
 * asserts this explicitly rather than silently omitting the row).
 */
class FinancialEvidencePanelFinancialTierAccessTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // The authoritative policy itself — the source of every
    // expectation below.
    // ------------------------------------------------------------

    public function test_the_authoritative_policy_bars_paralegal_legal_assistant_and_receptionist_from_the_view_tier(): void
    {
        $policy = app(FinancialIntegrationAccessPolicyService::class);

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $barred) {
            $this->assertFalse(
                $policy->canView($barred),
                "FinancialIntegrationAccessPolicyService's own docblock bars {$barred->value} from every financial-tier permission."
            );
        }

        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff] as $permitted) {
            $this->assertTrue($policy->canView($permitted));
        }
    }

    public function test_the_role_enum_has_no_auditor_case_so_that_matrix_row_is_not_applicable(): void
    {
        $values = array_map(fn (FirmUserRole $r): string => $r->value, FirmUserRole::cases());

        $this->assertCount(6, $values);

        foreach ($values as $value) {
            $this->assertStringNotContainsString('auditor', $value);
        }
    }

    // ------------------------------------------------------------
    // C2 — mount() must run BOTH gates, for all six panels.
    // ------------------------------------------------------------

    /**
     * The core C2 proof. Every role is given an ACTIVE MatterAssignment
     * first, so the matter-access gate passes for all of them and the
     * financial tier is the ONLY differentiator left — exactly the
     * situation the defect describes.
     */
    #[DataProvider('panelClassProvider')]
    public function test_mount_admits_exactly_the_roles_the_financial_tier_policy_admits(string $panelClass): void
    {
        $policy = app(FinancialIntegrationAccessPolicyService::class);
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        foreach (FirmUserRole::cases() as $role) {
            $firmUser = $this->makeAssignedFirmUser($firm, $matter, $role);
            $this->actingAs($firmUser->user);

            $panel = new $panelClass;

            if ($policy->canView($role)) {
                $this->runWithFirmContext($firm, fn () => $panel->mount($matter->id));

                $this->assertSame($matter->id, $panel->matterId, "{$role->value} holds the financial-tier view permission and must be admitted to ".class_basename($panelClass).'.');

                continue;
            }

            try {
                $this->runWithFirmContext($firm, fn () => $panel->mount($matter->id));
                $this->fail(
                    "{$role->value} is barred by FinancialIntegrationAccessPolicyService::canView() and must NOT be able to mount "
                    .class_basename($panelClass).' — a routine MatterAssignment is not a financial-tier grant.'
                );
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('financial-tier', $e->getMessage());
            }
        }
    }

    #[DataProvider('panelClassProvider')]
    public function test_render_re_asserts_the_financial_tier_on_every_request_not_only_at_mount(string $panelClass): void
    {
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        // Mount legitimately as an Attorney...
        $attorney = $this->makeAssignedFirmUser($firm, $matter, FirmUserRole::Attorney);
        $this->actingAs($attorney->user);

        $panel = new $panelClass;
        $this->runWithFirmContext($firm, fn () => $panel->mount($matter->id));

        // ...then the acting user's membership is demoted mid-session
        // (the exact scenario a mount-only gate would miss).
        $this->runWithFirmContext($firm, fn () => $attorney->update(['role' => FirmUserRole::Paralegal]));

        $this->expectException(RuntimeException::class);
        $this->runWithFirmContext($firm, fn () => $panel->render());
    }

    /**
     * A FirmUser in the SAME firm with no MatterAssignment at all is
     * stopped by the matter gate (which the financial tier never
     * replaces), and one in a DIFFERENT firm is stopped outright.
     */
    #[DataProvider('panelClassProvider')]
    public function test_mount_denies_an_unrelated_and_a_cross_firm_firm_user(string $panelClass): void
    {
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        // Unrelated: BillingStaff holds the financial tier but has no
        // MatterAssignment — "BillingStaff when not separately granted."
        $unrelated = $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->role(FirmUserRole::BillingStaff)
            ->create(['firm_id' => $firm->id]));

        $this->actingAs($unrelated->user);
        $panel = new $panelClass;

        try {
            $this->runWithFirmContext($firm, fn () => $panel->mount($matter->id));
            $this->fail('BillingStaff without a MatterAssignment must be denied by the matter-access gate on '.class_basename($panelClass).'.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        // Cross-firm: an Attorney (blanket matter access WITHIN their
        // own firm) at a different firm entirely.
        $otherFirm = Firm::factory()->create();
        $stranger = $this->runWithFirmContext($otherFirm, fn () => FirmUser::factory()
            ->role(FirmUserRole::Attorney)
            ->create(['firm_id' => $otherFirm->id]));

        $this->actingAs($stranger->user);
        $crossFirmPanel = new $panelClass;

        $this->expectException(AccessDeniedHttpException::class);
        $this->runWithFirmContext($firm, fn () => $crossFirmPanel->mount($matter->id));
    }

    // ------------------------------------------------------------
    // C2 — the DATA paths, not just mount().
    // ------------------------------------------------------------

    public function test_overview_panel_never_yields_account_names_masks_or_institutions_to_a_barred_role(): void
    {
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        $attorneyRows = $this->overviewRows($firm, $matter, FirmUserRole::Attorney);
        $this->assertCount(1, $attorneyRows);
        $this->assertSame('Operating Checking', $attorneyRows->first()['account_name']);
        $this->assertSame('4321', $attorneyRows->first()['mask']);

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $barred) {
            try {
                $this->overviewRows($firm, $matter, $barred);
                $this->fail("{$barred->value} must never read account names/masks/institutions from the Overview panel's table.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('financial-tier', $e->getMessage());
            }
        }
    }

    public function test_summary_panel_never_yields_income_liabilities_or_investments_to_a_barred_role(): void
    {
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        foreach (['incomeSection', 'liabilitySection', 'investmentSection', 'recurringObligationsSection'] as $section) {
            // Sanity: the permitted tier really can build the section.
            $this->actingAsAssigned($firm, $matter, FirmUserRole::Attorney);
            $panel = new FinancialEvidenceSummaryPanel;
            $panel->matterId = $matter->id;
            $this->runWithFirmContext($firm, fn () => $this->invokePrivate($panel, $section));

            foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $barred) {
                $this->actingAsAssigned($firm, $matter, $barred);

                $barredPanel = new FinancialEvidenceSummaryPanel;
                $barredPanel->matterId = $matter->id;

                try {
                    $this->runWithFirmContext($firm, fn () => $this->invokePrivate($barredPanel, $section));
                    $this->fail("{$barred->value} must never read {$section} data from the Summary panel.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('financial-tier', $e->getMessage());
                }
            }
        }
    }

    #[DataProvider('queuePanelProvider')]
    public function test_review_queue_tables_never_yield_review_candidates_to_a_barred_role(string $panelClass): void
    {
        [$firm, $matter] = $this->makeMatterWithFinancialEvidence();

        $this->actingAsAssigned($firm, $matter, FirmUserRole::Attorney);
        $permitted = new $panelClass;
        $permitted->matterId = $matter->id;
        $rows = $this->runWithFirmContext($firm, fn () => ($permitted->table(Table::make($permitted))->getDataSource())());
        $this->assertCount(1, $rows, 'Sanity check: the permitted tier must actually see the seeded queue row.');

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $barred) {
            $this->actingAsAssigned($firm, $matter, $barred);

            $panel = new $panelClass;
            $panel->matterId = $matter->id;
            $closure = $panel->table(Table::make($panel))->getDataSource();

            try {
                $this->runWithFirmContext($firm, fn () => $closure());
                $this->fail("{$barred->value} must never read review candidates from ".class_basename($panelClass).'.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('financial-tier', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------
    // C2 — EVERY mutation re-checks independently.
    // ------------------------------------------------------------

    /**
     * The mutation must fail even when the panel object was already
     * legitimately mounted by a permitted role — a mutation may never
     * rely on the page merely having been reachable.
     */
    #[DataProvider('mutationProvider')]
    public function test_every_review_queue_mutation_independently_re_checks_the_financial_tier(
        string $panelClass,
        string $method,
        string $seedKey,
        bool|string $secondArgument,
    ): void {
        [$firm, $matter, $seed] = $this->makeMatterWithFinancialEvidence();

        $actor = $this->makeAssignedFirmUser($firm, $matter, FirmUserRole::Attorney);
        $this->actingAs($actor->user);

        $panel = new $panelClass;
        $this->runWithFirmContext($firm, fn () => $panel->mount($matter->id));

        // Demoted AFTER a wholly legitimate mount.
        $this->runWithFirmContext($firm, fn () => $actor->update(['role' => FirmUserRole::Receptionist]));

        try {
            $this->runWithFirmContext($firm, fn () => $this->invokePrivate(
                $panel,
                $method,
                [$seed[$seedKey]->id, $secondArgument],
            ));
            $this->fail('A demoted actor must not be able to mutate review status via '.class_basename($panelClass)."::{$method}().");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('financial-tier', $e->getMessage());
        }

        $this->assertQueueRowUnresolved($firm, $seed);
    }

    public static function panelClassProvider(): array
    {
        return [
            'Overview' => [FinancialEvidenceOverviewPanel::class],
            'Summary' => [FinancialEvidenceSummaryPanel::class],
            'ReviewQueues' => [FinancialEvidenceReviewQueuesPanel::class],
            'DuplicateTransfersQueue' => [DuplicateTransfersQueuePanel::class],
            'LargeDepositsQueue' => [LargeDepositsQueuePanel::class],
            'ReconciliationCandidatesQueue' => [ReconciliationCandidatesQueuePanel::class],
        ];
    }

    public static function queuePanelProvider(): array
    {
        return [
            'DuplicateTransfersQueue' => [DuplicateTransfersQueuePanel::class],
            'LargeDepositsQueue' => [LargeDepositsQueuePanel::class],
            'ReconciliationCandidatesQueue' => [ReconciliationCandidatesQueuePanel::class],
        ];
    }

    public static function mutationProvider(): array
    {
        return [
            'duplicate transfers — dismiss' => [DuplicateTransfersQueuePanel::class, 'resolveFlag', 'duplicateFlag', true],
            'duplicate transfers — confirm' => [DuplicateTransfersQueuePanel::class, 'resolveFlag', 'duplicateFlag', false],
            'large deposits — dismiss' => [LargeDepositsQueuePanel::class, 'resolveFlag', 'largeDepositFlag', true],
            'large deposits — confirm' => [LargeDepositsQueuePanel::class, 'resolveFlag', 'largeDepositFlag', false],
            'reconciliation — reject' => [ReconciliationCandidatesQueuePanel::class, 'decide', 'candidate', 'rejected'],
            'reconciliation — confirm as ledger match' => [ReconciliationCandidatesQueuePanel::class, 'decide', 'candidate', 'confirmed_match'],
        ];
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Matter, 2: array<string, mixed>}
     */
    private function makeMatterWithFinancialEvidence(): array
    {
        // Durable Firm required: every financial-tier denial below runs
        // through FinancialIntegrationAccessPolicyService::recordDenied(),
        // which writes integration_governance.action_denied on the
        // independent 'pgsql_audit' connection — that connection cannot
        // see a Firm still uncommitted inside this test's
        // RefreshDatabase transaction. Same shape as
        // FinancialEvidenceImmutabilityAndProvenanceTest::
        // test_a_paralegal_may_not_write_a_note_billing_staff_may().
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        [$matter, $seed] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'account_name' => 'Operating Checking',
                'mask' => '4321',
                'raw_json' => [],
            ]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
            ]);

            $txnA = $this->makeTransaction($firm, $account, 250_00);
            $txnB = $this->makeTransaction($firm, $account, -250_00);

            $duplicateFlag = FinancialEvidenceDuplicateTransferFlag::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'transaction_id_a' => $txnA->id,
                'transaction_id_b' => $txnB->id,
                'detected_at' => now(),
            ]);

            $largeDepositFlag = FinancialEvidenceLargeDepositFlag::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'transaction_id' => $txnA->id,
                'threshold_cents_applied' => 100_00,
                'detected_at' => now(),
            ]);

            $candidate = FinancialEvidenceReconciliationCandidate::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'transaction_id' => $txnA->id,
                'trust_ledger_entry_id' => null,
                'match_confidence' => 'high',
                'status' => 'candidate',
            ]);

            return [$matter, [
                'connection' => $connection,
                'account' => $account,
                'transaction' => $txnA,
                'duplicateFlag' => $duplicateFlag,
                'largeDepositFlag' => $largeDepositFlag,
                'candidate' => $candidate,
            ]];
        });

        return [$firm, $matter, $seed];
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account, int $amountCents): FinancialEvidenceTransaction
    {
        return FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => $amountCents,
            'transaction_date' => now()->toDateString(),
            'merchant_name' => 'Seeded Merchant',
            'pending' => false,
            'raw_json' => [],
        ]);
    }

    /**
     * Every role gets an ACTIVE MatterAssignment, so the matter-access
     * gate passes for all of them and the financial tier is the only
     * remaining differentiator.
     */
    private function makeAssignedFirmUser(Firm $firm, Matter $matter, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->role($role)
            ->forUser($user)
            ->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext(
            $firm,
            fn () => MatterAssignment::factory()->forMatter($matter)->forUser($user)->create(),
        );

        return $firmUser;
    }

    private function actingAsAssigned(Firm $firm, Matter $matter, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->makeAssignedFirmUser($firm, $matter, $role);
        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    private function overviewRows(Firm $firm, Matter $matter, FirmUserRole $role)
    {
        $this->actingAsAssigned($firm, $matter, $role);

        $panel = new FinancialEvidenceOverviewPanel;
        $panel->matterId = $matter->id;

        $closure = $panel->table(Table::make($panel))->getDataSource();

        return $this->runWithFirmContext($firm, fn () => $closure());
    }

    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }

    private function assertQueueRowUnresolved(Firm $firm, array $seed): void
    {
        $this->runWithFirmContext($firm, function () use ($seed): void {
            $this->assertNull($seed['duplicateFlag']->fresh()->dismissed_at);
            $this->assertNull($seed['duplicateFlag']->fresh()->confirmed_at);
            $this->assertNull($seed['largeDepositFlag']->fresh()->dismissed_at);
            $this->assertNull($seed['largeDepositFlag']->fresh()->confirmed_at);
            $this->assertSame('candidate', $seed['candidate']->fresh()->status);
        });
    }

    /**
     * Copied verbatim from IntegrationAccessPolicyServiceTest's own
     * private helper of the same name — see that copy's docblock for
     * the full "why beforeApplicationDestroyed(), not an inline
     * finally" reasoning. timeline_events has permanent FORCE ROW LEVEL
     * SECURITY, so the DELETE must run with app.current_firm_id set to
     * this firm's id on the SAME 'pgsql_audit' connection performing it.
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }
}
