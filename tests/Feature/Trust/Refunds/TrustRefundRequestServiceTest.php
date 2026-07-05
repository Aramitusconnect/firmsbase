<?php

namespace Tests\Feature\Trust\Refunds;

use App\Enums\FirmUserRole;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustRefundRequestStatus;
use App\Models\Client;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustRefundRequestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustRefundRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustRefundRequestService::class);
    }

    private function setupFundedLedger(int $depositAmount = 10000): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, $depositAmount), $user);
        $deposits->post($firm, $ledger, $approved);

        return [$firm, $ledger, $user];
    }

    public function test_full_refund_lifecycle_posts_a_refund_entry(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(10000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 4000);
        $this->service->approveRefund($firm, $request, $user);
        $entry = $this->service->complete($firm, $request->fresh(), $user);

        $this->assertSame(TrustLedgerEntryType::Refund, $entry->entry_type);
        $this->assertSame(-4000, $entry->amount_cents);
        $this->assertSame(TrustRefundRequestStatus::Completed, $request->fresh()->status);
        $this->assertSame(6000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_refund_cannot_exceed_the_available_balance(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(1000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 5000);
        $this->service->approveRefund($firm, $request, $user);

        $this->expectException(\RuntimeException::class);
        $this->service->complete($firm, $request->fresh(), $user);
    }

    public function test_refund_cannot_be_completed_before_approval(): void
    {
        [$firm, $ledger, $user] = $this->setupFundedLedger(10000);

        $request = $this->service->requestRefund($firm, $ledger, $user, 4000);

        $this->expectException(\RuntimeException::class);
        $this->service->complete($firm, $request, $user);
    }
}
