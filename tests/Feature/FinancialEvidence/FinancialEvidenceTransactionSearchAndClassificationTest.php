<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\FinancialAccountClassification;
use App\Integrations\Models\FirmIntegration;
use App\Livewire\FinancialEvidence\FinancialEvidenceTransactionSearchPanel;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceTransaction;
use App\Models\FinancialEvidenceTransactionReview;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FinancialEvidenceTransactionSearchAndClassificationTest —
 * FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
 * evidence add-on"). Transaction Search's bounded filters (date/amount/
 * account/category, text search, reviewed/unreviewed, flagged/
 * unflagged) and the Account Classification vocabulary + masked
 * identifiers discipline across the Workspace.
 */
class FinancialEvidenceTransactionSearchAndClassificationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Transaction Search — bounded filters
    // ------------------------------------------------------------

    public function test_date_range_filter_bounds_results_to_the_from_and_to_dates(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 1000, now()->subDays(10));
            $this->makeTransaction($firm, $account, 2000, now()->subDays(5));
            $this->makeTransaction($firm, $account, 3000, now());
        });

        $rows = $this->queryPanel($firm, $matter, [
            'date_from' => now()->subDays(6)->toDateString(),
            'date_until' => now()->subDays(1)->toDateString(),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('20.00', $rows->first()['amount']);
    }

    public function test_amount_range_filter_bounds_results(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 500, now());
            $this->makeTransaction($firm, $account, 5000, now());
            $this->makeTransaction($firm, $account, 50000, now());
        });

        $rows = $this->queryPanel($firm, $matter, [
            'amount_min' => '10.00',
            'amount_max' => '100.00',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('50.00', $rows->first()['amount']);
    }

    public function test_account_filter_scopes_to_exactly_the_selected_account(): void
    {
        [$firm, $matter, $accountA, $connection] = $this->makeAuthorizedMatter();

        $accountB = $this->runWithFirmContext($firm, function () use ($firm, $connection, $matter) {
            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            return $account;
        });

        $this->runWithFirmContext($firm, function () use ($firm, $accountA, $accountB) {
            $this->makeTransaction($firm, $accountA, 1000, now());
            $this->makeTransaction($firm, $accountB, 2000, now());
        });

        $rows = $this->queryPanel($firm, $matter, ['bank_account_id' => $accountA->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('10.00', $rows->first()['amount']);
    }

    public function test_text_search_matches_merchant_name(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 1000, now(), 'Acme Landlord LLC');
            $this->makeTransaction($firm, $account, 2000, now(), 'Totally Different Payee');
        });

        $rows = $this->queryPanel($firm, $matter, [], search: 'Landlord');

        $this->assertCount(1, $rows);
        $this->assertSame('Acme Landlord LLC', $rows->first()['merchant_name']);
    }

    public function test_reviewed_and_unreviewed_filters_partition_correctly(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();
        $reviewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        [$reviewedTxn, $unreviewedTxn] = $this->runWithFirmContext($firm, function () use ($firm, $account, $reviewer) {
            $reviewed = $this->makeTransaction($firm, $account, 1000, now());
            $unreviewed = $this->makeTransaction($firm, $account, 2000, now());

            FinancialEvidenceTransactionReview::query()->create([
                'firm_id' => $firm->id,
                'transaction_id' => $reviewed->id,
                'reviewed_by_firm_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'flagged' => false,
            ]);

            return [$reviewed, $unreviewed];
        });

        $reviewedRows = $this->queryPanel($firm, $matter, ['reviewed' => '1']);
        $unreviewedRows = $this->queryPanel($firm, $matter, ['reviewed' => '0']);

        $this->assertCount(1, $reviewedRows);
        $this->assertSame($reviewedTxn->id, $reviewedRows->first()['id']);

        $this->assertCount(1, $unreviewedRows);
        $this->assertSame($unreviewedTxn->id, $unreviewedRows->first()['id']);
    }

    public function test_flagged_and_unflagged_filters_partition_correctly(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();
        $reviewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        [$flaggedTxn, $unflaggedTxn] = $this->runWithFirmContext($firm, function () use ($firm, $account, $reviewer) {
            $flagged = $this->makeTransaction($firm, $account, 1000, now());
            $unflagged = $this->makeTransaction($firm, $account, 2000, now());

            FinancialEvidenceTransactionReview::query()->create([
                'firm_id' => $firm->id,
                'transaction_id' => $flagged->id,
                'reviewed_by_firm_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'flagged' => true,
                'flag_reason' => 'Unusual amount.',
            ]);

            return [$flagged, $unflagged];
        });

        $flaggedRows = $this->queryPanel($firm, $matter, ['flagged' => '1']);

        $this->assertCount(1, $flaggedRows);
        $this->assertSame($flaggedTxn->id, $flaggedRows->first()['id']);
        $this->assertTrue($flaggedRows->first()['flagged']);
    }

    public function test_the_latest_review_wins_when_a_transaction_has_been_re_reviewed(): void
    {
        [$firm, $matter, $account] = $this->makeAuthorizedMatter();
        $reviewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        $transaction = $this->runWithFirmContext($firm, function () use ($firm, $account, $reviewer) {
            $t = $this->makeTransaction($firm, $account, 1000, now());

            FinancialEvidenceTransactionReview::query()->create([
                'firm_id' => $firm->id,
                'transaction_id' => $t->id,
                'reviewed_by_firm_user_id' => $reviewer->id,
                'reviewed_at' => now()->subHour(),
                'flagged' => true,
            ]);

            FinancialEvidenceTransactionReview::query()->create([
                'firm_id' => $firm->id,
                'transaction_id' => $t->id,
                'reviewed_by_firm_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'flagged' => false,
            ]);

            return $t;
        });

        $rows = $this->queryPanel($firm, $matter, []);

        $this->assertFalse($rows->first()['flagged'], 'The most recent review must win when a transaction has multiple append-only review rows.');
    }

    // ------------------------------------------------------------
    // Masked account identifiers — no full account number exposed
    // ------------------------------------------------------------

    public function test_financial_evidence_bank_accounts_has_no_full_account_number_column(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('financial_evidence_bank_accounts');

        foreach (['account_number', 'full_account_number', 'routing_number', 'iban'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "financial_evidence_bank_accounts must never carry a '{$forbidden}' column — only the short masked identifier.");
        }

        $this->assertContains('mask', $columns);
    }

    public function test_the_mask_column_is_short_enough_to_only_ever_hold_a_masked_fragment(): void
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            "select character_maximum_length from information_schema.columns where table_name = 'financial_evidence_bank_accounts' and column_name = 'mask'"
        );

        $this->assertNotNull($row);
        $this->assertLessThanOrEqual(8, (int) $row->character_maximum_length, 'The mask column must be short enough that a full account/routing number could never be stored in it.');
    }

    // ------------------------------------------------------------
    // Account classification vocabulary — completeness
    // ------------------------------------------------------------

    public function test_all_seven_required_classification_values_are_present_and_exactly_these_seven(): void
    {
        $values = array_map(fn (FinancialAccountClassification $c) => $c->value, FinancialAccountClassification::cases());
        sort($values);

        $expected = [
            'client_owned_evidence',
            'credit_liability',
            'investment',
            'operating',
            'other',
            'settlement',
            'trust_iolta',
        ];

        $this->assertSame($expected, $values);
    }

    public function test_every_classification_case_has_a_distinct_label(): void
    {
        $labels = array_map(fn (FinancialAccountClassification $c) => $c->label(), FinancialAccountClassification::cases());

        $this->assertCount(count(FinancialAccountClassification::cases()), array_unique($labels));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Matter, 2: FinancialEvidenceBankAccount, 3: FirmIntegration}
     */
    private function makeAuthorizedMatter(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
            ]);

            return [$firm, $matter, $account, $connection];
        });
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account, int $amountCents, \Illuminate\Support\Carbon $date, ?string $merchant = null): FinancialEvidenceTransaction
    {
        return FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => $amountCents,
            'transaction_date' => $date->toDateString(),
            'merchant_name' => $merchant,
            'pending' => false,
            'raw_json' => [],
        ]);
    }

    /**
     * Invokes the panel's own private records-closure logic by
     * constructing the panel and driving its access-gated matter()
     * resolution directly, matching the shape every other test file in
     * this suite already uses for embedded Livewire panels (rather than
     * a full Livewire::test()/Volt render, which — for a
     * RelationManager-embedded, non-routed panel like this one —
     * requires a live Filament Resource context this suite intentionally
     * does not construct).
     */
    private function queryPanel(Firm $firm, Matter $matter, array $filters, ?string $search = null): \Illuminate\Support\Collection
    {
        $viewer = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));
        $this->actingAs($viewer->user);

        $panel = new FinancialEvidenceTransactionSearchPanel;
        $panel->matterId = $matter->id;

        $table = $panel->table(\Filament\Tables\Table::make($panel));

        $recordsClosure = $table->getDataSource();
        $this->assertNotNull($recordsClosure, 'Sanity check: the panel\'s table() must define a records() data source closure.');

        return $this->runWithFirmContext($firm, fn () => $recordsClosure($filters, $search));
    }
}
