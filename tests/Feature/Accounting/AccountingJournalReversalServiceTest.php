<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\AccountingJournalPostingService;
use App\Services\AccountingJournalReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingJournalReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingJournalPostingService $postingService;

    private AccountingJournalReversalService $reversalService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postingService = app(AccountingJournalPostingService::class);
        $this->reversalService = app(AccountingJournalReversalService::class);
    }

    private function makeFundedEntry(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $entry = $this->postingService->post(
            $firm,
            AccountingJournalSourceType::InvoicePaymentApplied,
            'Original entry',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 30000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 30000],
            ],
        );

        return [$firm, $entry, $cash, $revenue];
    }

    public function test_reversal_swaps_every_posting_lines_debit_and_credit(): void
    {
        [$firm, $entry, $cash, $revenue] = $this->makeFundedEntry();

        $reversal = $this->reversalService->reverse($firm, $entry, 'Correcting an error');

        $this->assertSame($entry->id, $reversal->reverses_journal_entry_id);
        $this->assertSame(2, $reversal->postings->count());

        $cashLine = $reversal->postings->firstWhere('chart_of_account_id', $cash->id);
        $revenueLine = $reversal->postings->firstWhere('chart_of_account_id', $revenue->id);

        $this->assertSame(0, $cashLine->debit_cents);
        $this->assertSame(30000, $cashLine->credit_cents);
        $this->assertSame(30000, $revenueLine->debit_cents);
        $this->assertSame(0, $revenueLine->credit_cents);
    }

    public function test_reversal_never_mutates_the_original_entry_or_its_postings(): void
    {
        [$firm, $entry, $cash] = $this->makeFundedEntry();
        $originalPostingAttributes = $entry->postings->map->getAttributes()->all();

        $this->reversalService->reverse($firm, $entry, 'Correcting an error');

        $entryFresh = $this->runWithFirmContext($firm, fn () => $entry->fresh('postings'));
        $freshPostingAttributes = $entryFresh->postings->map->getAttributes()->all();

        $this->assertEquals($originalPostingAttributes, $freshPostingAttributes);
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        [$firm, $entry] = $this->makeFundedEntry();
        $this->reversalService->reverse($firm, $entry, 'First reversal');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been reversed/');

        $this->reversalService->reverse($firm, $entry, 'Second reversal attempt');
    }

    public function test_a_reversal_still_balances(): void
    {
        [$firm, $entry] = $this->makeFundedEntry();

        $reversal = $this->reversalService->reverse($firm, $entry, 'Correcting an error');

        $this->assertGreaterThan(0, $reversal->postings->sum('debit_cents'));
        $this->assertSame(
            $reversal->postings->sum('debit_cents'),
            $reversal->postings->sum('credit_cents'),
        );
    }

    public function test_cannot_reverse_an_entry_belonging_to_another_firm(): void
    {
        [, $entry] = $this->makeFundedEntry();
        $otherFirm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to this firm/');

        $this->reversalService->reverse($otherFirm, $entry, 'Cross-firm reversal attempt');
    }
}
