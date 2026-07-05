<?php

namespace Tests\Feature\Trust\Accounts;

use App\Enums\TrustAccountStatus;
use App\Models\Firm;
use App\Services\TrustAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustAccountServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustAccountService::class);
    }

    public function test_eligible_firm_can_open_a_trust_account(): void
    {
        $firm = $this->makeTrustEligibleFirm();

        $account = $this->service->open($firm, 'Firm IOLTA Trust Account');

        $this->assertSame(TrustAccountStatus::Active, $account->status);
        $this->assertDatabaseHas('trust_accounts', ['id' => $account->id, 'firm_id' => $firm->id]);
    }

    public function test_ineligible_firm_cannot_open_a_trust_account(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->open($firm, 'Firm IOLTA Trust Account');
    }

    public function test_account_can_be_suspended_and_closed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = $this->service->open($firm, 'Firm IOLTA Trust Account');

        $this->service->suspend($firm, $account);
        $this->assertSame(TrustAccountStatus::Suspended, $account->fresh()->status);

        $this->service->close($firm, $account->fresh());
        $this->assertSame(TrustAccountStatus::Closed, $account->fresh()->status);
    }
}
