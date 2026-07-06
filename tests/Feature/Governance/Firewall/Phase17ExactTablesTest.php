<?php

namespace Tests\Feature\Governance\Firewall;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\TenantEncryptionKeyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms exactly the 13 approved Phase 17 tables exist — no extra
 * governance/offboarding/deletion/access/vendor table was introduced —
 * and that the reused enums (HighRiskChangeRequestStatus,
 * TenantEncryptionKeyStatus) are still the exact same enums Phase 17
 * code imports, never duplicated.
 */
class Phase17ExactTablesTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_TABLES = [
        'retention_policies',
        'legal_holds',
        'offboarding_requests',
        'offboarding_exports',
        'key_destruction_requests',
        'key_destruction_approvals',
        'deletion_requests',
        'deletion_approvals',
        'access_reviews',
        'access_review_items',
        'vendor_register',
        'subprocessors',
        'data_processing_records',
    ];

    public function test_all_thirteen_phase_17_tables_exist(): void
    {
        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist.");
        }
    }

    public function test_no_extra_phase_17_governance_table_exists(): void
    {
        $allTables = collect(Schema::getTables())->pluck('name')->all();

        $governanceLikePrefixes = ['retention_', 'legal_hold', 'offboarding_', 'key_destruction_',
            'deletion_', 'access_review', 'vendor_', 'subprocessor', 'data_processing_'];

        $unexpected = collect($allTables)
            ->filter(fn (string $name) => collect($governanceLikePrefixes)->contains(fn ($prefix) => str_starts_with($name, $prefix)))
            ->reject(fn (string $name) => in_array($name, self::EXPECTED_TABLES, true))
            ->values()
            ->all();

        $this->assertEmpty($unexpected, 'Unexpected Phase 17 table(s) found: '.implode(', ', $unexpected));
    }

    public function test_high_risk_change_type_gained_exactly_one_additive_case(): void
    {
        $values = array_map(fn (HighRiskChangeType $case) => $case->value, HighRiskChangeType::cases());

        $this->assertContains('cryptographic_key_destruction', $values);
        $this->assertContains('trust_mode_activation', $values);
        $this->assertContains('production_data_deletion', $values);
        $this->assertContains('payment_trust_setting_change', $values);
        $this->assertContains('emergency_support_access', $values);
        $this->assertContains('dedicated_legal_specialist_approval', $values);
        $this->assertContains('operating_only_trust_disable_acknowledgment', $values);
        $this->assertCount(7, HighRiskChangeType::cases());
    }

    public function test_high_risk_change_request_status_and_tenant_encryption_key_status_are_not_duplicated(): void
    {
        $this->assertNotEmpty(HighRiskChangeRequestStatus::cases());
        $this->assertNotEmpty(TenantEncryptionKeyStatus::cases());
        $this->assertContains('destroyed', array_map(fn ($c) => $c->value, TenantEncryptionKeyStatus::cases()));
    }
}
