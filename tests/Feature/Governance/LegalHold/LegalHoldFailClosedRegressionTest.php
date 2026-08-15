<?php

namespace Tests\Feature\Governance\LegalHold;

use App\Enums\DeletionRequestStatus;
use App\Enums\LegalHoldScope;
use App\Enums\OffboardingExportStatus;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\RetentionPolicy;
use App\Services\DeletionGovernanceService;
use App\Services\LegalHoldService;
use App\Services\OffboardingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

/**
 * LEGAL HOLD FAIL-OPEN REGRESSION — the single most important safety
 * proof in the Governance console mission.
 *
 * `legal_holds` carries permanent FORCE ROW LEVEL SECURITY. An
 * unwrapped SELECT under FORCE returns ZERO ROWS rather than raising,
 * so a missing or wrong tenant context can silently turn "an active
 * hold exists" into "not blocked" — a false negative on the one gate
 * protecting every destructive governance workflow.
 *
 * Before LegalHoldService::checkHold() established its own tenant
 * context, this was empirically reproducible: a real, Active,
 * Firm-scope hold read back as `blocked: false` when checkHold() was
 * invoked with no ambient context. These tests lock that closed.
 *
 * §138 of the mission brief: the safe outcome may be blocked, or a
 * thrown exception, or an explicit "unable to verify" — but it must
 * NEVER be a false "no hold".
 */
class LegalHoldFailClosedRegressionTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private LegalHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LegalHoldService::class);
    }

    // -----------------------------------------------------------------
    // The core regression: no ambient tenant context at all.
    // -----------------------------------------------------------------

    public function test_active_hold_is_still_detected_with_no_ambient_tenant_context(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        $this->assertNoDatabaseTenantContext(
            'this test must begin with genuinely no tenant context, or it proves nothing'
        );

        $result = $this->service->checkHold($firm, LegalHoldScope::Firm);

        $this->assertTrue(
            $result->blocked,
            'FAIL-OPEN REGRESSION: an active Firm-scope hold was reported as NOT blocking '
            .'when checkHold() ran without ambient tenant context. An unwrapped read under '
            .'FORCE RLS returns zero rows, which must never be interpreted as "no hold".'
        );
        $this->assertNotEmpty($result->activeHoldIds, 'the blocking hold id must be reported');
    }

    public function test_matter_scoped_hold_is_still_detected_with_no_ambient_tenant_context(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = $this->makeGovernanceMatter($firm);

        $this->service->place($firm, LegalHoldScope::Matter, 'Preservation notice.', $admin, null, $matter);

        $this->assertNoDatabaseTenantContext();

        $this->assertTrue(
            $this->service->checkHold($firm, LegalHoldScope::Matter, $matter->id)->blocked,
            'FAIL-OPEN REGRESSION: an active Matter-scope hold was missed with no ambient context.'
        );
    }

    // -----------------------------------------------------------------
    // Wrong-context and cross-firm cases. A hold must be evaluated
    // against the firm the CALLER named, never against whatever context
    // happened to be ambient.
    // -----------------------------------------------------------------

    public function test_active_hold_is_still_detected_while_a_different_firms_context_is_ambient(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firmA, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        // Firm B's context is ambient while we ask about Firm A.
        $blocked = $this->runWithFirmContext(
            $firmB,
            fn () => $this->service->checkHold($firmA, LegalHoldScope::Firm)->blocked
        );

        $this->assertTrue(
            $blocked,
            'FAIL-OPEN REGRESSION: Firm A\'s active hold became invisible because Firm B\'s '
            .'context was ambient. checkHold() must evaluate the firm it was asked about.'
        );
    }

    public function test_one_firms_hold_does_not_block_another_firm(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firmA, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        $this->assertFalse(
            $this->service->checkHold($firmB, LegalHoldScope::Firm)->blocked,
            'an unrelated firm\'s hold must not block this firm — fail-closed must not become fail-shut-for-everyone'
        );
    }

    // -----------------------------------------------------------------
    // Context hygiene: the gate must not leak or destroy context.
    // -----------------------------------------------------------------

    public function test_check_hold_leaves_no_tenant_context_behind(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        $this->assertNoDatabaseTenantContext();
        $this->service->checkHold($firm, LegalHoldScope::Firm);
        $this->assertNoDatabaseTenantContext('checkHold() must clear its own context wrap before returning');
    }

    public function test_check_hold_restores_an_outer_ambient_context_rather_than_clearing_it(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firmB, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        $this->runWithFirmContext($firmA, function () use ($firmA, $firmB) {
            $this->service->checkHold($firmB, LegalHoldScope::Firm);

            $this->assertSame(
                (string) $firmA->id,
                $this->currentDatabaseTenantContextValue(),
                'checkHold() must restore the caller\'s ambient context, not wipe it'
            );
        });
    }

    // -----------------------------------------------------------------
    // The three destructive callers still block correctly. These are the
    // workflows the gate actually protects.
    // -----------------------------------------------------------------

    public function test_deletion_clearance_reports_the_hold_as_blocking_with_no_ambient_context(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $matter = $this->makeGovernanceMatter($firm);

        $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        // Deletion clearance evaluates export -> retention -> legal hold
        // in order and short-circuits, so the earlier two gates must be
        // satisfied for the hold gate to be reached at all.
        $request = $this->makeClearableDeletionRequest($firm, $matter->id);

        $this->assertNoDatabaseTenantContext();

        $clearance = app(DeletionGovernanceService::class)
            ->checkClearance($request, RetentionRecordType::Matter);

        $this->assertTrue($clearance->exportCleared, 'PRECONDITION: export gate must be cleared');
        $this->assertTrue($clearance->retentionCleared, 'PRECONDITION: retention gate must be cleared');
        $this->assertFalse(
            $clearance->legalHoldCleared,
            'FAIL-OPEN REGRESSION: an active Firm-scope hold must block deletion clearance'
        );
        $this->assertFalse($clearance->isClear());
    }

    public function test_offboarding_readiness_reports_the_hold_as_blocking_with_no_ambient_context(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $this->service->place($firm, LegalHoldScope::Firm, 'Pending litigation.', $admin);

        $request = $this->runWithFirmContext($firm, fn () => OffboardingRequest::factory()->create([
            'firm_id' => $firm->id,
        ]));

        $this->assertNoDatabaseTenantContext();

        $readiness = app(OffboardingRequestService::class)->evaluateReadiness($request);

        $this->assertFalse(
            $readiness->legalHoldCleared,
            'FAIL-OPEN REGRESSION: an active Firm-scope hold must block offboarding readiness'
        );
        $this->assertFalse($readiness->isReady());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function currentDatabaseTenantContextValue(): ?string
    {
        $value = DB::selectOne(
            'select current_setting(?, true) as value',
            ['app.current_firm_id']
        )?->value;

        return $value === '' ? null : $value;
    }

    private function makeGovernanceMatter(Firm $firm): Matter
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => Matter::factory()->create(['firm_id' => $firm->id])
        );
    }

    /**
     * A deletion request whose export and retention gates both clear, so
     * that checkClearance() reaches the legal-hold gate rather than
     * short-circuiting before it.
     */
    private function makeClearableDeletionRequest(Firm $firm, int $subjectId): DeletionRequest
    {
        // Retention clears only against a real Active policy whose window
        // has elapsed — isRetentionCleared() treats "no policy" as NOT
        // cleared, never unrestricted. A 0-day platform-default policy is
        // the minimal shape that lets the chain reach the hold gate.
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 0,
            'is_permanent' => false,
            'status' => RetentionPolicyStatus::Active,
            'effective_at' => now()->subDay(),
        ]);

        return $this->runWithFirmContext($firm, function () use ($firm, $subjectId) {
            $offboardingRequest = OffboardingRequest::factory()->create(['firm_id' => $firm->id]);

            // offboarding_exports carries no firm_id of its own — it is a
            // derived table, tenant-scoped through offboarding_request_id.
            $export = OffboardingExport::factory()->create([
                'offboarding_request_id' => $offboardingRequest->id,
                'status' => OffboardingExportStatus::Verified,
            ]);

            return DeletionRequest::factory()->create([
                'firm_id' => $firm->id,
                'subject_id' => $subjectId,
                'offboarding_export_id' => $export->id,
                'status' => DeletionRequestStatus::Requested,
            ]);
        });
    }
}
