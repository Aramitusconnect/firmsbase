<?php

namespace Tests\Feature\Governance\LegalHold;

use App\Enums\LegalHoldScope;
use App\Models\LegalHold;
use App\Services\LegalHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class LegalHoldServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private LegalHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LegalHoldService::class);
    }

    public function test_firm_level_active_hold_blocks_the_whole_firm(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        // legal_holds carries permanent FORCE ROW LEVEL SECURITY.
        // LegalHoldService::checkHold()/hasActiveHold() deliberately
        // carry no wrap of their own (a shared helper invoked by
        // several other services, each of which wraps its own call) —
        // a direct test call like this one must establish its own
        // tenant context explicitly, exactly like every real caller
        // (DeletionGovernanceService::checkClearance(),
        // KeyDestructionRequestService::checkClearance(),
        // OffboardingRequestService::evaluateReadiness()) now does.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertTrue($this->service->hasActiveHold($firm, LegalHoldScope::Firm));
            $this->assertTrue($this->service->hasActiveHold($firm, LegalHoldScope::Matter, 12345));
        });
    }

    public function test_no_hold_means_not_blocked(): void
    {
        $firm = $this->makeGovernanceFirm();

        $this->assertFalse($this->service->hasActiveHold($firm, LegalHoldScope::Firm));
    }

    public function test_releasing_a_hold_unblocks_it(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $hold = $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);
        // See test_firm_level_active_hold_blocks_the_whole_firm()'s own
        // comment: checkHold()/hasActiveHold() carry no wrap of their
        // own under legal_holds' permanent FORCE ROW LEVEL SECURITY, so
        // a direct test call must establish its own tenant context.
        $this->runWithFirmContext($firm, fn () => $this->assertTrue($this->service->hasActiveHold($firm, LegalHoldScope::Firm)));

        $this->service->release($hold, $admin, 'Litigation concluded.');

        $this->runWithFirmContext($firm, fn () => $this->assertFalse($this->service->hasActiveHold($firm, LegalHoldScope::Firm)));
    }

    public function test_matter_scoped_hold_only_blocks_that_matter(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = \App\Models\Matter::factory()->create(['firm_id' => $firm->id]);
        $otherMatter = \App\Models\Matter::factory()->create(['firm_id' => $firm->id]);

        $this->service->place($firm, LegalHoldScope::Matter, 'Matter under hold.', $admin, matter: $matter);

        $this->assertTrue($this->service->hasActiveHold($firm, LegalHoldScope::Matter, $matter->id));
        $this->assertFalse($this->service->hasActiveHold($firm, LegalHoldScope::Matter, $otherMatter->id));
    }

    public function test_legal_hold_is_tenant_scoped(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firmA, LegalHoldScope::Firm, 'Firm A hold.', $admin);

        // See test_firm_level_active_hold_blocks_the_whole_firm()'s own
        // comment: checkHold()/hasActiveHold() carry no wrap of their
        // own under legal_holds' permanent FORCE ROW LEVEL SECURITY, so
        // each direct test call must establish its own tenant context,
        // keyed on the firm actually being checked.
        $this->runWithFirmContext($firmA, fn () => $this->assertTrue($this->service->hasActiveHold($firmA, LegalHoldScope::Firm)));
        $this->runWithFirmContext($firmB, fn () => $this->assertFalse($this->service->hasActiveHold($firmB, LegalHoldScope::Firm)));
    }
}
