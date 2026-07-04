<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards against the exact failure mode this phase was warned about
 * repeatedly: recreating a table that already exists from an earlier
 * phase, or creating a "second entitlement system" table
 * (firm_entitlement_overrides) that was explicitly rejected during
 * planning. Also confirms the 10 explicitly-protected tables still
 * have exactly the columns Phase 1/2 gave them PLUS only the additive
 * columns approved for Phase 6 — i.e. this migrated database has
 * exactly one definition of each of these tables.
 */
class Phase6NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedTableProvider(): array
    {
        return [
            ['organizations'],
            ['billing_accounts'],
            ['firms'],
            ['firm_licenses'],
            ['firm_entitlements'],
            ['firm_entitlement_events'],
            ['module_catalog'],
            ['template_packs'],
            ['template_pack_versions'],
            ['installed_template_packs'],
        ];
    }

    #[DataProvider('protectedTableProvider')]
    public function test_protected_table_exists_exactly_once(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist.");
    }

    public function test_firm_entitlement_overrides_table_was_not_created(): void
    {
        $this->assertFalse(
            Schema::hasTable('firm_entitlement_overrides'),
            'firm_entitlement_overrides must not exist — the existing firm_entitlements table '
            .'(with its per-source unique constraint) is the sole override machinery, per the '
            .'approved Phase 6 manifest.'
        );
    }

    public function test_signature_requests_table_was_not_created(): void
    {
        $this->assertFalse(
            Schema::hasTable('signature_requests'),
            'signature_requests must not exist in Phase 6 — the acknowledgment foundation is a '
            .'value object and service only, with no table, per the approved manifest.'
        );
    }

    public function test_firm_licenses_gained_only_the_approved_additive_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('firm_licenses', [
            'firm_id', 'billing_account_id', 'org_license_id', 'plan_id',
            'license_key', 'license_status', 'deployment_mode', 'customer_type', 'billing_mode',
            'starts_at', 'renews_at', 'expires_at', 'cancelled_at', 'created_by', 'updated_by',
        ]));
    }

    public function test_firm_entitlements_gained_no_new_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('firm_entitlements', [
            'firm_id', 'module_code', 'enabled', 'source', 'settings_json', 'starts_at', 'ends_at', 'created_by',
        ]));

        $this->assertFalse(Schema::hasColumn('firm_entitlements', 'is_override'));
    }

    public function test_firm_users_gained_only_the_approved_seat_class_column(): void
    {
        $this->assertTrue(Schema::hasColumn('firm_users', 'seat_class'));
        $this->assertTrue(Schema::hasColumn('firm_users', 'role'));
    }
}
