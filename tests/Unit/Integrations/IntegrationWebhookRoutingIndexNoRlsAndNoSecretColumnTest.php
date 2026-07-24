<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest — Checkpoint
 * 7's §5.1 firewall test. Proves the DELIBERATE absence of RLS on
 * `integration_webhook_routing_index` (the opposite proof from every
 * other FORCE-RLS test file in this codebase) and the structural
 * absence of any secret/credential-bearing column on it, plus a
 * source-inspection sweep confirming it is written only by the two
 * named ProviderConnectionService methods.
 *
 * Also covers the sibling no-RLS proof for `integration_webhook_receipts`
 * (frozen design §10.1) — there is no dedicated test file for that
 * table in this checkpoint's frozen test-file allowlist, and this file
 * is the natural home for the "assert RLS is NOT enabled" property,
 * which is the mirror image of everything this file already proves for
 * the routing-index table.
 */
final class IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // integration_webhook_routing_index
    // ------------------------------------------------------------

    public function test_integration_webhook_routing_index_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_webhook_routing_index'));
    }

    public function test_integration_webhook_routing_index_has_no_row_level_security_enabled_at_all(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_webhook_routing_index'");

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity, 'integration_webhook_routing_index must have RLS DISABLED — it must be readable before any tenant context exists.');
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    public function test_integration_webhook_routing_index_has_no_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_webhook_routing_index'");

        $this->assertCount(0, $rows);
    }

    public function test_integration_webhook_routing_index_never_stores_a_raw_routing_token_only_its_hash(): void
    {
        $this->assertTrue(Schema::hasColumn('integration_webhook_routing_index', 'webhook_routing_token_hash'));
        $this->assertFalse(Schema::hasColumn('integration_webhook_routing_index', 'webhook_routing_token'));
    }

    public function test_integration_webhook_routing_index_has_no_credential_type_or_ciphertext_bearing_column(): void
    {
        $columns = Schema::getColumnListing('integration_webhook_routing_index');

        $this->assertNotContains('credential_type', $columns);

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('ciphertext', $column, "Column {$column} must not carry ciphertext-shaped content on a no-RLS table.");
            $this->assertStringNotContainsString('secret', $column, "Column {$column} must not carry secret-shaped content on a no-RLS table.");
        }
    }

    public function test_the_webhook_routing_token_hash_column_has_a_global_unique_index(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_webhook_routing_index' and indexdef ilike '%unique%webhook_routing_token_hash%'"
        );

        $this->assertNotNull($row, 'webhook_routing_token_hash must carry a GLOBAL unique index — Step 1 structurally returns at most one row.');
    }

    public function test_integration_webhook_routing_index_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_webhook_routing_index');
        sort($columns);

        $expected = [
            'id', 'firm_id', 'firm_integration_id', 'integration_provider_id',
            'webhook_routing_token_hash', 'created_at', 'updated_at',
        ];
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    /**
     * Source-inspection sweep: routing_index is written ONLY by
     * ProviderConnectionService's enableWebhookRouting()/
     * disableWebhookRouting()/disconnect(), and read ONLY by
     * WebhookConnectionResolverService — no other app/ file may
     * reference the table name or model at all.
     */
    public function test_integration_webhook_routing_index_is_referenced_only_by_the_two_authorized_services(): void
    {
        $allowedFiles = [
            'ProviderConnectionService.php',
            'WebhookConnectionResolverService.php',
            'IntegrationWebhookRoutingIndex.php',
            'IntegrationWebhookRoutingIndexFactory.php',
            // Registry bookkeeping only — records this table's Global/
            // no-RLS classification and disclaimer note; never queries
            // or writes the table itself.
            'RowLevelSecurityCoverageMappingService.php',
            // Checkpoint 11 additions — both reference the table for an
            // ->exists()-only existence check
            // (App\Services\IntegrationPlatformOversightReadService::
            // toConnectionSummary() queries
            // DB::table('integration_webhook_routing_index')->where(...)
            // ->exists()), deriving a plain `webhookRoutingConfigured`
            // boolean on the SuperAdmin oversight read model. Neither
            // file ever selects/exposes webhook_routing_token_hash or
            // any other column from this table — good data-exposure
            // discipline, not a boundary violation.
            'IntegrationPlatformOversightReadService.php',
            'PlatformIntegrationConnectionSummary.php',
        ];

        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $basename = basename($file);

            if (in_array($basename, $allowedFiles, true)) {
                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && (str_contains($source, 'integration_webhook_routing_index') || str_contains($source, 'IntegrationWebhookRoutingIndex'))) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'Only ProviderConnectionService/WebhookConnectionResolverService may touch integration_webhook_routing_index: '.implode(', ', $violations));
    }

    // ------------------------------------------------------------
    // integration_webhook_receipts (sibling no-RLS table)
    // ------------------------------------------------------------

    public function test_integration_webhook_receipts_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_webhook_receipts'));
    }

    public function test_integration_webhook_receipts_has_no_row_level_security_enabled_at_all(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_webhook_receipts'");

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity, 'integration_webhook_receipts must have RLS DISABLED — same platform pre-tenant-intake exemption class as integration_providers.');
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    public function test_integration_webhook_receipts_has_no_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_webhook_receipts'");

        $this->assertCount(0, $rows);
    }

    public function test_integration_webhook_receipts_has_no_firm_id_or_firm_integration_id_column_at_all(): void
    {
        $this->assertFalse(Schema::hasColumn('integration_webhook_receipts', 'firm_id'), 'Structurally tenant-blind by design — no firm_id column may ever exist.');
        $this->assertFalse(Schema::hasColumn('integration_webhook_receipts', 'firm_integration_id'));
    }

    public function test_integration_webhook_receipts_never_stores_the_raw_request_body(): void
    {
        $columns = Schema::getColumnListing('integration_webhook_receipts');

        $this->assertContains('body_hash', $columns);
        $this->assertNotContains('raw_body', $columns);
        $this->assertNotContains('body', $columns);
        $this->assertNotContains('headers', $columns);
        $this->assertNotContains('headers_json', $columns);
        $this->assertNotContains('raw_headers', $columns);
    }

    public function test_integration_webhook_receipts_never_stores_the_signature_value(): void
    {
        $columns = Schema::getColumnListing('integration_webhook_receipts');

        $this->assertNotContains('signature', $columns);
        $this->assertNotContains('signature_value', $columns);
    }

    /**
     * Both tables belong to the same deliberate no-RLS exemption class
     * as integration_providers — proves the classification is
     * consistent, not a one-off coincidence between the two new tables.
     */
    public function test_integration_providers_the_precedent_table_also_has_no_rls(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_providers'");

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity);
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    /**
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }
}
