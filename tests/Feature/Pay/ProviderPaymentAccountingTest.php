<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentAttemptState;
use App\Enums\PaymentClassification;
use App\Enums\PaymentDestinationClass;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\Pay\ProviderPaymentJournalRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\CleansUpDurablePayAudit;
use Tests\TestCase;

/**
 * FV-A2-040 … FV-A2-047 — accounting. CERTIFICATION BLOCKING.
 *
 * The load-bearing assertion is FV-A2-041:
 *
 *     A CARD CAPTURE MUST NOT DEBIT OPERATING CASH.
 *
 * and its twin FV-A2-040:
 *
 *     CASH/CHEQUE PAYMENTS MUST STILL DEBIT OPERATING CASH.
 *
 * Together they prove the new provider path was added ALONGSIDE the
 * existing behavior rather than replacing it (v1.4 §32).
 */
class ProviderPaymentAccountingTest extends TestCase
{
    use CleansUpDurablePayAudit;
    use RefreshDatabase;

    private function intents(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    private function attempts(): PaymentAttemptService
    {
        return app(PaymentAttemptService::class);
    }

    private function payJournal(): ProviderPaymentJournalRecorderService
    {
        return app(ProviderPaymentJournalRecorderService::class);
    }

    /**
     * A firm with the accounting module enabled and a complete chart of
     * accounts, including the two Gate A2 additions.
     */
    private function firmWithAccounting(): Firm
    {
        $firm = Firm::factory()->create();

        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        $purposes = [
            [ChartOfAccountPurpose::OperatingCash, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::LegalFeeRevenue, ChartOfAccountType::Revenue],
            [ChartOfAccountPurpose::CostReimbursementRevenue, ChartOfAccountType::Revenue],
            [ChartOfAccountPurpose::ProcessorClearingOperating, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::ProviderSettlementReceivable, ChartOfAccountType::Asset],
            [ChartOfAccountPurpose::ProcessorFees, ChartOfAccountType::Expense],
            [ChartOfAccountPurpose::UnappliedOperatingFundsLiability, ChartOfAccountType::Liability],
        ];

        $this->runWithFirmContext($firm, function () use ($firm, $purposes) {
            foreach ($purposes as [$purpose, $type]) {
                ChartOfAccount::factory()->forFirm($firm)->create([
                    'purpose' => $purpose,
                    'account_type' => $type,
                    'is_active' => true,
                ]);
            }
        });

        return $firm;
    }

    private function accountIdFor(Firm $firm, ChartOfAccountPurpose $purpose): int
    {
        return (int) $this->runWithFirmContext($firm, fn () => ChartOfAccount::query()
            ->where('firm_id', $firm->id)
            ->where('purpose', $purpose->value)
            ->value('id'));
    }

    /** FV-A2-043 / FV-A2-044 — the required account purposes exist. */
    public function test_fv_a2_043_and_044_new_and_reused_account_purposes_are_available(): void
    {
        $this->assertSame('processor_clearing_operating', ChartOfAccountPurpose::ProcessorClearingOperating->value);
        $this->assertSame('provider_settlement_receivable', ChartOfAccountPurpose::ProviderSettlementReceivable->value);

        // ProcessorFees is REUSED, not re-created (v1.4 §34).
        $this->assertSame('processor_fees', ChartOfAccountPurpose::ProcessorFees->value);

        $firm = $this->firmWithAccounting();

        $this->assertGreaterThan(0, $this->accountIdFor($firm, ChartOfAccountPurpose::ProcessorClearingOperating));
        $this->assertGreaterThan(0, $this->accountIdFor($firm, ChartOfAccountPurpose::ProviderSettlementReceivable));
        $this->assertGreaterThan(0, $this->accountIdFor($firm, ChartOfAccountPurpose::ProcessorFees));
    }

    /**
     * FV-A2-041 — THE central accounting assertion.
     * A provider capture must NOT debit Operating Cash.
     */
    public function test_fv_a2_041_provider_capture_does_not_debit_operating_cash(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $entry = $this->payJournal()->recordProviderCapture($firm, $attempt, feeCents: 10_000, costCents: 0);

        $this->assertNotNull($entry);

        $operatingCashId = $this->accountIdFor($firm, ChartOfAccountPurpose::OperatingCash);
        $clearingId = $this->accountIdFor($firm, ChartOfAccountPurpose::ProcessorClearingOperating);

        $postings = $this->runWithFirmContext($firm, fn () => DB::table('accounting_postings')
            ->where('accounting_journal_entry_id', $entry->id)->get());

        $debitedAccounts = $postings->where('debit_cents', '>', 0)->pluck('chart_of_account_id')->all();

        $this->assertNotContains(
            $operatingCashId,
            $debitedAccounts,
            'A card capture is NOT bank cash — Operating Cash must not be debited at capture.'
        );
        $this->assertContains(
            $clearingId,
            $debitedAccounts,
            'A card capture must debit the processor clearing account instead.'
        );
    }

    /** FV-A2-042 — the clearing entry balances. */
    public function test_fv_a2_042_processor_clearing_entry_is_balanced(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 30_000);

        $entry = $this->payJournal()->recordProviderCapture($firm, $attempt, feeCents: 20_000, costCents: 10_000);

        $postings = $this->runWithFirmContext($firm, fn () => DB::table('accounting_postings')
            ->where('accounting_journal_entry_id', $entry->id)->get());

        $debits = (int) $postings->sum('debit_cents');
        $credits = (int) $postings->sum('credit_cents');

        $this->assertSame(30_000, $debits);
        $this->assertSame(30_000, $credits);
        $this->assertSame($debits, $credits, 'Every journal entry must balance.');

        // The credit legs are the EXISTING revenue accounts — revenue is
        // recognized once, in the same place it always was.
        $creditedAccounts = $postings->where('credit_cents', '>', 0)->pluck('chart_of_account_id')->all();
        $this->assertContains($this->accountIdFor($firm, ChartOfAccountPurpose::LegalFeeRevenue), $creditedAccounts);
        $this->assertContains($this->accountIdFor($firm, ChartOfAccountPurpose::CostReimbursementRevenue), $creditedAccounts);
    }

    /**
     * FV-A2-040 — REGRESSION. The existing cash/cheque path is
     * untouched and still debits Operating Cash (v1.4 §32).
     */
    public function test_fv_a2_040_existing_cash_payment_still_debits_operating_cash(): void
    {
        $firm = $this->firmWithAccounting();

        [$client, $invoice, $actor] = $this->billingFixtures($firm);

        $payment = app(ManualPaymentService::class)->submit(
            firm: $firm,
            client: $client,
            amountCents: 10_000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: 'regression-cheque-'.$firm->id,
            invoice: $invoice,
            recordedBy: $actor->user,
        );

        $this->assertNotNull($payment);

        $operatingCashId = $this->accountIdFor($firm, ChartOfAccountPurpose::OperatingCash);

        $debited = $this->runWithFirmContext($firm, fn () => DB::table('accounting_postings')
            ->where('chart_of_account_id', $operatingCashId)
            ->where('debit_cents', '>', 0)
            ->count());

        $this->assertGreaterThan(
            0,
            $debited,
            'A cheque payment IS real bank cash and must still debit Operating Cash — Gate A2 must not '
            .'have changed the existing non-processor path.'
        );
    }

    /** FV-A2-045 — a duplicate capture posting is blocked. */
    public function test_fv_a2_045_duplicate_provider_capture_posting_is_blocked(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $first = $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);
        $second = $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);

        $this->assertSame($first->id, $second->id, 'A replayed capture must reuse the original journal entry.');

        $entries = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::query()
            ->where('payment_attempt_id', $attempt->id)->count());

        $this->assertSame(1, $entries, 'Exactly one journal entry per captured attempt.');
    }

    /**
     * FV-A2-046 — legal revenue is not duplicated. The capture posting
     * credits revenue exactly once, and a replay adds nothing.
     */
    public function test_fv_a2_046_legal_revenue_is_not_duplicated(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);
        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);
        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);

        $revenueId = $this->accountIdFor($firm, ChartOfAccountPurpose::LegalFeeRevenue);

        $totalRevenue = (int) $this->runWithFirmContext($firm, fn () => DB::table('accounting_postings')
            ->where('chart_of_account_id', $revenueId)
            ->sum('credit_cents'));

        $this->assertSame(
            10_000,
            $totalRevenue,
            'Three capture postings for the same attempt must recognize revenue ONCE.'
        );
    }

    /** A non-captured attempt can never post. */
    public function test_only_a_captured_attempt_can_post_a_provider_capture(): void
    {
        $firm = $this->firmWithAccounting();
        $intent = $this->executableIntent($firm, 10_000);
        $attempt = $this->attempts()->open($intent);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/only a captured attempt/');

        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);
    }

    /** An unbalanced fee/cost split is refused before it reaches the ledger. */
    public function test_unbalanced_fee_and_cost_split_is_refused(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/unbalanced by construction/');

        $this->payJournal()->recordProviderCapture($firm, $attempt, 4_000, 4_000);
    }

    /**
     * FV-A2-047 — the trust ledger firewall still holds: the Pay
     * accounting path never writes any trust_* table.
     */
    public function test_fv_a2_047_trust_ledger_firewall_regression(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);

        $this->runWithFirmContext($firm, function () {
            foreach (['trust_ledgers', 'trust_ledger_entries', 'trust_balances', 'matter_trust_balances', 'trust_accounts'] as $table) {
                $this->assertSame(
                    0,
                    DB::table($table)->count(),
                    "The FirmsVault Pay accounting path must never write [{$table}]."
                );
            }
        });
    }

    /**
     * v1.4 §29 — Billing's accounting basis is preserved. No Accounts
     * Receivable posting was introduced.
     */
    public function test_no_accounts_receivable_posting_was_introduced(): void
    {
        $firm = $this->firmWithAccounting();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $this->payJournal()->recordProviderCapture($firm, $attempt, 10_000, 0);

        $arAccount = $this->runWithFirmContext($firm, fn () => ChartOfAccount::query()
            ->where('firm_id', $firm->id)
            ->where('purpose', ChartOfAccountPurpose::AccountsReceivable->value)
            ->first());

        $this->assertNull(
            $arAccount,
            'Gate A2 must not require an AR account: the repository recognizes revenue at cash receipt '
            .'and that basis is preserved (v1.4 §29).'
        );
    }

    // ---------------------------------------------------------------

    private function executableIntent(Firm $firm, int $amountCents): PaymentIntent
    {
        $intent = $this->intents()->createDraft($firm, $amountCents, 'invoice_payment');
        $this->intents()->addAllocation($intent, PaymentDestinationClass::Operating, $amountCents);

        return $this->intents()->freeze($intent);
    }

    private function capturedAttempt(Firm $firm, int $amountCents): PaymentAttempt
    {
        $intent = $this->executableIntent($firm, $amountCents);
        $attempt = $this->attempts()->open($intent);
        $submitted = $this->attempts()->transition($attempt, PaymentAttemptState::Submitted);

        return $this->attempts()->transition($submitted, PaymentAttemptState::Captured, providerReference: 'TEST-REF');
    }

    /**
     * @return array{0: Client, 1: Invoice, 2: FirmUser}
     */
    private function billingFixtures(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm): array {
            $client = Client::factory()->create(['firm_id' => $firm->id]);
            // Must be Sent: the existing ManualPaymentService refuses to
            // apply a payment to an unsent/unapproved invoice, and this
            // regression test exercises that real path unchanged.
            $invoice = Invoice::factory()
                ->forClient($client)
                ->status(InvoiceStatus::Sent)
                ->create(['total_cents' => 10_000]);
            $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

            return [$client, $invoice, $firmUser];
        });
    }
}
