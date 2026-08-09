<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountType;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\AccountingEarnedFeeService;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustTransferRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Phase C — earned vs. unearned is a distinct question from
 * PaymentClassificationService (Operating/Trust/Blocked). This proves
 * the two read-only sources of truth stay correctly separated: a
 * trust deposit alone changes ONLY the unearned figure (nothing is
 * posted to the operating books for money still sitting in trust);
 * a transfer moves the exact transferred amount from unearned to
 * earned, leaving the remainder still unearned.
 */
class AccountingEarnedFeeServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    public function test_a_trust_deposit_alone_is_unearned_and_posts_nothing_to_the_operating_books(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());

        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $depositRequest = $deposits->requestDeposit($firm, $ledger, $requester, 20000, $matter);
        $approvedDeposit = $deposits->approveDeposit($firm, $depositRequest, $approver);
        $deposits->post($firm, $ledger, $approvedDeposit, $matter);

        $service = app(AccountingEarnedFeeService::class);

        $this->assertSame(20000, $service->unearnedBalanceCentsForClient($firm, $client));
        $this->assertSame(20000, $service->unearnedBalanceCentsForMatter($firm, $matter));
        $this->assertSame(0, $service->earnedFeesCentsForClient($firm, $client));
        $this->assertSame(0, $service->earnedFeesCentsForMatter($firm, $matter));
    }

    public function test_a_partial_transfer_moves_exactly_the_transferred_amount_from_unearned_to_earned(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'matter_id' => $matter->id, 'subtotal_cents' => 12000, 'total_cents' => 12000,
        ]));

        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $depositRequest = $deposits->requestDeposit($firm, $ledger, $requester, 20000, $matter);
        $approvedDeposit = $deposits->approveDeposit($firm, $depositRequest, $approver);
        $deposits->post($firm, $ledger, $approvedDeposit, $matter);

        $transferService = app(TrustTransferRequestService::class);
        $request = $transferService->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 12000);
        $transferService->approveTransfer($firm, $request, $approver);
        $transferService->apply($firm, $request->fresh(), $approver);

        $service = app(AccountingEarnedFeeService::class);

        $this->assertSame(8000, $service->unearnedBalanceCentsForMatter($firm, $matter));
        $this->assertSame(12000, $service->earnedFeesCentsForMatter($firm, $matter));
        $this->assertSame(8000, $service->unearnedBalanceCentsForClient($firm, $client));
        $this->assertSame(12000, $service->earnedFeesCentsForClient($firm, $client));
    }

    public function test_earned_and_unearned_are_zero_with_no_chart_of_accounts_or_trust_activity(): void
    {
        $firm = \App\Models\Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());

        $service = app(AccountingEarnedFeeService::class);

        $this->assertSame(0, $service->unearnedBalanceCentsForClient($firm, $client));
        $this->assertSame(0, $service->earnedFeesCentsForClient($firm, $client));
        $this->assertSame(0, $service->unearnedBalanceCentsForMatter($firm, $matter));
        $this->assertSame(0, $service->earnedFeesCentsForMatter($firm, $matter));
    }
}
