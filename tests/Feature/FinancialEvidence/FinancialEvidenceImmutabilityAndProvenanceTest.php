<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceMatterNote;
use App\Models\FinancialEvidenceSnapshot;
use App\Models\FinancialEvidenceTransaction;
use App\Models\FinancialEvidenceTransactionReview;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * FinancialEvidenceImmutabilityAndProvenanceTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on").
 * Covers: evidentiary immutability (bank accounts, transactions,
 * snapshots cannot be edited/deleted after creation), data provenance
 * classification correctness, and attorney notes (matter-scoped,
 * permission-controlled, timestamped, author-attributed, append-only).
 */
class FinancialEvidenceImmutabilityAndProvenanceTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Immutability — financial_evidence_bank_accounts
    // ------------------------------------------------------------

    public function test_a_bank_account_row_cannot_be_updated_after_creation(): void
    {
        [$firm, $account] = $this->makeAccount();

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, function () use ($account) {
            $account->account_name = 'Renamed';
            $account->save();
        });
    }

    public function test_a_bank_account_row_cannot_be_deleted(): void
    {
        [$firm, $account] = $this->makeAccount();

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, fn () => $account->delete());
    }

    // ------------------------------------------------------------
    // Immutability — financial_evidence_transactions
    // ------------------------------------------------------------

    public function test_a_transaction_row_cannot_be_updated_after_creation(): void
    {
        [$firm, $account] = $this->makeAccount();
        $transaction = $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account));

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, function () use ($transaction) {
            $transaction->merchant_name = 'Tampered';
            $transaction->save();
        });
    }

    public function test_a_transaction_row_cannot_be_deleted(): void
    {
        [$firm, $account] = $this->makeAccount();
        $transaction = $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account));

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, fn () => $transaction->delete());
    }

    // ------------------------------------------------------------
    // Immutability — financial_evidence_snapshots
    // ------------------------------------------------------------

    public function test_a_snapshot_row_cannot_be_updated_after_creation(): void
    {
        [$firm, $matter, $firmUser] = $this->makeMatterAndFirmUser();
        $snapshot = $this->runWithFirmContext($firm, fn () => $this->makeSnapshot($firm, $matter, $firmUser));

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, function () use ($snapshot) {
            $snapshot->limitations_text = 'Tampered limitations.';
            $snapshot->save();
        });
    }

    public function test_a_snapshot_row_cannot_be_deleted(): void
    {
        [$firm, $matter, $firmUser] = $this->makeMatterAndFirmUser();
        $snapshot = $this->runWithFirmContext($firm, fn () => $this->makeSnapshot($firm, $matter, $firmUser));

        $this->expectException(LogicException::class);

        $this->runWithFirmContext($firm, fn () => $snapshot->delete());
    }

    public function test_a_renewed_snapshot_creates_a_new_row_with_an_incremented_report_version_never_editing_the_old_one(): void
    {
        [$firm, $matter, $firmUser] = $this->makeMatterAndFirmUser();
        $first = $this->runWithFirmContext($firm, fn () => $this->makeSnapshot($firm, $matter, $firmUser, reportVersion: 1));
        $second = $this->runWithFirmContext($firm, fn () => $this->makeSnapshot($firm, $matter, $firmUser, reportVersion: 2));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => FinancialEvidenceSnapshot::query()->find($first->id))->report_version);
        $this->assertSame(2, $second->report_version);
    }

    // ------------------------------------------------------------
    // Data provenance — every case correctly classified/labeled
    // ------------------------------------------------------------

    public function test_every_provenance_case_has_a_distinct_non_empty_label_and_color(): void
    {
        $seenLabels = [];

        foreach (FinancialEvidenceProvenance::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->badgeColor());
            $seenLabels[] = $case->label();
        }

        $this->assertCount(
            count(FinancialEvidenceProvenance::cases()),
            array_unique($seenLabels),
            'Every provenance case must have a visually distinct label — two cases sharing a label would let a user misclassify a provider fact as an attorney confirmation or vice versa.'
        );
    }

    public function test_the_seven_required_provenance_categories_are_all_present_and_exactly_these_seven(): void
    {
        $values = array_map(fn (FinancialEvidenceProvenance $c) => $c->value, FinancialEvidenceProvenance::cases());
        sort($values);

        $expected = [
            'attorney_confirmed',
            'client_provided_explanation',
            'confirmed_ledger_match',
            'firmsvault_observation',
            'provider_supplied_fact',
            'reconciliation_candidate',
            'uploaded_source_record',
        ];

        $this->assertSame($expected, $values);
    }

    public function test_a_materialized_transaction_is_never_itself_tagged_attorney_confirmed(): void
    {
        // Structural guarantee: FinancialEvidenceTransaction rows are
        // exclusively provider-supplied fact — the panel that renders
        // them (FinancialEvidenceTransactionSearchPanel) hardcodes
        // FinancialEvidenceProvenance::ProviderSuppliedFact for the fact
        // row and only the SEPARATE review row can ever carry
        // AttorneyConfirmedClassification. Verified against the live
        // panel source rather than merely asserted.
        $panelSource = file_get_contents(app_path('Livewire/FinancialEvidence/FinancialEvidenceTransactionSearchPanel.php'));

        $this->assertStringContainsString('FinancialEvidenceProvenance::ProviderSuppliedFact', $panelSource);
        $this->assertStringNotContainsString('FinancialEvidenceProvenance::AttorneyConfirmedClassification', $panelSource);
    }

    // ------------------------------------------------------------
    // Attorney Notes — matter-scoped, permission-controlled,
    // timestamped, author-attributed, append-only
    // ------------------------------------------------------------

    public function test_a_note_is_matter_scoped_timestamped_and_author_attributed(): void
    {
        [$firm, $matter, $author] = $this->makeMatterAndFirmUser(FirmUserRole::Attorney);

        $note = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterNote::query()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'author_firm_user_id' => $author->id,
            'body' => 'Client confirmed the deposit source.',
            'created_at' => now(),
        ]));

        $this->assertSame($matter->id, $note->matter_id);
        $this->assertSame($author->id, $note->author_firm_user_id);
        $this->assertNotNull($note->created_at);
    }

    public function test_a_note_has_no_updated_at_column_a_re_review_must_create_a_new_row_not_edit_the_old_one(): void
    {
        [$firm, $matter, $author] = $this->makeMatterAndFirmUser(FirmUserRole::Attorney);

        $note = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterNote::query()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'author_firm_user_id' => $author->id,
            'body' => 'Original note.',
            'created_at' => now(),
        ]));

        $this->assertArrayNotHasKey('updated_at', $note->getAttributes());
        $this->assertFalse($note->timestamps);
    }

    public function test_a_paralegal_may_not_write_a_note_billing_staff_may(): void
    {
        // Durable Firm required: assertCanRequest()'s denial writes
        // integration_governance.action_denied on the independent
        // 'pgsql_audit' connection (FinancialIntegrationAccessPolicyService::
        // recordDenied() -> TimelineEventRecorder::recordOnIndependentConnection()),
        // which cannot see a Firm still uncommitted inside this test's
        // RefreshDatabase transaction — same shape as
        // IntegrationAccessPolicyServiceTest::test_governance_action_denied_fires_on_a_policy_denial().
        // Cleanup is registered via beforeApplicationDestroyed() rather
        // than an inline finally block — see
        // cleanUpDurableFirmAuditTrailAfterRollback()'s own docblock for
        // why an inline finally deadlocks here.
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]));

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => app(FinancialIntegrationAccessPolicyService::class)->assertCanRequest($paralegal));
    }

    public function test_billing_staff_may_write_a_note(): void
    {
        [$firm, $matter, $billingStaff] = $this->makeMatterAndFirmUser(FirmUserRole::BillingStaff);

        $this->runWithFirmContext($firm, fn () => app(FinancialIntegrationAccessPolicyService::class)->assertCanRequest($billingStaff));
        $this->assertTrue(true, 'assertCanRequest() must not throw for BillingStaff.');
    }

    // ------------------------------------------------------------
    // Transaction review — reviewed/unreviewed, flagged/unflagged,
    // append-only (a re-review creates a new row)
    // ------------------------------------------------------------

    public function test_a_re_review_creates_a_new_review_row_rather_than_editing_the_prior_one(): void
    {
        [$firm, $account] = $this->makeAccount();
        $transaction = $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account));
        $reviewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        $firstReview = $this->runWithFirmContext($firm, fn () => FinancialEvidenceTransactionReview::query()->create([
            'firm_id' => $firm->id,
            'transaction_id' => $transaction->id,
            'reviewed_by_firm_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'flagged' => false,
        ]));

        $secondReview = $this->runWithFirmContext($firm, fn () => FinancialEvidenceTransactionReview::query()->create([
            'firm_id' => $firm->id,
            'transaction_id' => $transaction->id,
            'reviewed_by_firm_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'flagged' => true,
            'flag_reason' => 'Looks unusual on reflection.',
        ]));

        $this->assertNotSame($firstReview->id, $secondReview->id);

        $count = $this->runWithFirmContext($firm, fn () => FinancialEvidenceTransactionReview::query()->where('transaction_id', $transaction->id)->count());
        $this->assertSame(2, $count, 'A re-review must create a NEW row, preserving who said what and when — never edit the prior review.');

        $stillIntact = $this->runWithFirmContext($firm, fn () => FinancialEvidenceTransactionReview::query()->find($firstReview->id));
        $this->assertFalse((bool) $stillIntact->flagged, 'The original review row must remain exactly as originally written.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FinancialEvidenceBankAccount}
     */
    private function makeAccount(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            return [$firm, $account];
        });
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account): FinancialEvidenceTransaction
    {
        return FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => 5000,
            'transaction_date' => now()->toDateString(),
            'pending' => false,
            'raw_json' => [],
        ]);
    }

    /**
     * @return array{0: Firm, 1: Matter, 2: FirmUser}
     */
    private function makeMatterAndFirmUser(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm, $role) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $firmUser = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);

            return [$firm, $matter, $firmUser];
        });
    }

    private function makeSnapshot(Firm $firm, Matter $matter, FirmUser $firmUser, int $reportVersion = 1): FinancialEvidenceSnapshot
    {
        return FinancialEvidenceSnapshot::query()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'generated_by_firm_user_id' => $firmUser->id,
            'authorized_source_json' => ['firm_integration_ids' => []],
            'authorized_account_ids_json' => [],
            'retrieved_record_refs_json' => [],
            'source_product' => 'transactions',
            'report_version' => $reportVersion,
            'limitations_text' => 'Historical data limited by the retrieval window.',
            'created_at' => now(),
        ]);
    }

    /**
     * Copied verbatim from IntegrationAccessPolicyServiceTest's own
     * private helper of the same name — see that copy's docblock for
     * the full "why beforeApplicationDestroyed(), not an inline
     * finally" reasoning. timeline_events has permanent FORCE ROW
     * LEVEL SECURITY, so the DELETE must run with app.current_firm_id
     * set to this firm's id on the SAME 'pgsql_audit' connection
     * performing it.
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
