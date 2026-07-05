<?php

namespace Tests\Feature\Trust\Firewall;

use App\Models\Client;
use App\Models\Firm;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustRefundRequestService;
use App\Services\TrustTransferRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Project rule 13 (UUIDv7 for public identifiers): every externally-
 * referenceable Phase 13 root/workflow record must carry a public uuid.
 * The 7 models that use HasPublicUuid are: TrustAccount, TrustLedger,
 * TrustBalance, TrustTransferRequest, TrustRefundRequest,
 * TrustChargebackEvent, TrustReconciliation. The other 3
 * (MatterTrustBalance, TrustLedgerEntry, TrustApprovalEvent) are pure
 * audit/cache rows referenced only by their internal bigint id, mirroring
 * established Phase 8-12 precedent.
 */
class TrustPublicUuidCoverageTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    public function test_trust_account_and_ledger_and_balance_carry_a_public_uuid(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $this->assertNotNull($account->uuid);
        $this->assertNotNull($ledger->uuid);
        $this->assertNotNull($ledger->balance->uuid);
    }

    public function test_transfer_and_refund_requests_carry_a_public_uuid(): void
    {
        $transfer = \App\Models\TrustTransferRequest::factory()->create();
        $refund = \App\Models\TrustRefundRequest::factory()->create();

        $this->assertNotNull($transfer->uuid);
        $this->assertNotNull($refund->uuid);
    }

    public function test_chargeback_event_and_reconciliation_carry_a_public_uuid(): void
    {
        $chargeback = \App\Models\TrustChargebackEvent::factory()->create();
        $reconciliation = \App\Models\TrustReconciliation::factory()->create();

        $this->assertNotNull($chargeback->uuid);
        $this->assertNotNull($reconciliation->uuid);
    }

    public function test_matter_trust_balance_and_ledger_entry_and_approval_event_do_not_carry_a_public_uuid_column(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('matter_trust_balances', 'uuid'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('trust_ledger_entries', 'uuid'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('trust_approval_events', 'uuid'));
    }
}
