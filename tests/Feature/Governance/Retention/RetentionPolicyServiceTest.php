<?php

namespace Tests\Feature\Governance\Retention;

use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\RetentionPolicy;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class RetentionPolicyServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private RetentionPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RetentionPolicyService::class);
    }

    public function test_firm_override_wins_over_platform_default(): void
    {
        $firm = $this->makeGovernanceFirm();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 3650,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $override = RetentionPolicy::factory()->forFirm($firm)->create([
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 30,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $resolved = $this->service->resolveEffectivePolicyFor($firm, RetentionRecordType::Matter);

        $this->assertSame($override->id, $resolved->id);
    }

    public function test_falls_back_to_platform_default_when_no_firm_override_exists(): void
    {
        $firm = $this->makeGovernanceFirm();

        $default = RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Lead,
            'retention_period_days' => 365,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $resolved = $this->service->resolveEffectivePolicyFor($firm, RetentionRecordType::Lead);

        $this->assertSame($default->id, $resolved->id);
    }

    public function test_no_policy_means_not_cleared_never_unrestricted(): void
    {
        $outcome = $this->service->isRetentionCleared(null, now()->subYears(50));

        $this->assertFalse($outcome->cleared);
    }

    public function test_permanent_trust_ledger_policy_blocks_clearance_regardless_of_age(): void
    {
        $policy = RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::TrustLedger,
            'is_permanent' => true,
            'retention_period_days' => null,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $outcome = $this->service->isRetentionCleared($policy, now()->subYears(100));

        $this->assertFalse($outcome->cleared);
    }

    public function test_non_permanent_policy_clears_after_retention_period_elapses(): void
    {
        $policy = RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'is_permanent' => false,
            'retention_period_days' => 30,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $notYetCleared = $this->service->isRetentionCleared($policy, now()->subDays(10));
        $this->assertFalse($notYetCleared->cleared);

        $cleared = $this->service->isRetentionCleared($policy, now()->subDays(31));
        $this->assertTrue($cleared->cleared);
    }

    public function test_client_document_replacement_requires_both_allows_replacement_and_audit_preservation(): void
    {
        $allowsOnly = RetentionPolicy::factory()->create([
            'allows_client_replacement' => true,
            'preserves_audit_history_required' => false,
        ]);
        $this->assertFalse($this->service->allowsClientDocumentReplacement($allowsOnly));

        $auditOnly = RetentionPolicy::factory()->create([
            'allows_client_replacement' => false,
            'preserves_audit_history_required' => true,
        ]);
        $this->assertFalse($this->service->allowsClientDocumentReplacement($auditOnly));

        $both = RetentionPolicy::factory()->create([
            'allows_client_replacement' => true,
            'preserves_audit_history_required' => true,
        ]);
        $this->assertTrue($this->service->allowsClientDocumentReplacement($both));

        $this->assertFalse($this->service->allowsClientDocumentReplacement(null));
    }

    public function test_clients_cannot_hard_delete_submitted_documents_by_default(): void
    {
        $policy = RetentionPolicy::factory()->create([
            'record_type' => RetentionRecordType::DocumentCategory,
            'document_category' => 'immigration_forms',
        ]);

        $this->assertFalse($policy->allows_client_replacement);
        $this->assertFalse($this->service->allowsClientDocumentReplacement($policy));
    }
}
