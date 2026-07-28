<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * IntegrationGmailMailboxRoutesNoRlsAndHmacOnlyColumnTest — Checkpoint 3
 * (FirmsVault Live Integrations, Google Workspace provider),
 * checkpoint3-security-review.md Finding 3 /
 * checkpoint3-design-sync-webhooks.md §10.5. Mirrors
 * `tests/Unit/Integrations/IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest.php`'s
 * exact structure for the new, dedicated `integration_gmail_mailbox_routes`
 * table: proves the DELIBERATE absence of RLS (the identical Global/
 * no-RLS classification `integration_webhook_routing_index` itself
 * carries, for the identical structural reason — this table must be
 * queryable before any tenant context exists, to bootstrap Gmail Cloud
 * Pub/Sub inbound-webhook mailbox-to-connection routing), the structural
 * absence of any plaintext-mailbox-bearing column (a keyed HMAC lookup
 * value plus an already-encrypted display ciphertext only — never a bare
 * SHA-256 the way the sibling table's own CSPRNG-token hash is, and
 * never the raw mailbox address itself), and a source-inspection sweep
 * confirming it is referenced only by
 * `App\Integrations\Support\GmailMailboxRoutingService` (the sole
 * writer/reader), its own return-value data object
 * (`App\Integrations\Data\ResolvedGmailMailboxRoute`), and the RLS
 * coverage registry's own bookkeeping
 * (`App\Services\RowLevelSecurityCoverageMappingService`).
 */
final class IntegrationGmailMailboxRoutesNoRlsAndHmacOnlyColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_gmail_mailbox_routes_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_gmail_mailbox_routes'));
    }

    public function test_integration_gmail_mailbox_routes_has_no_row_level_security_enabled_at_all(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_gmail_mailbox_routes'");

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity, 'integration_gmail_mailbox_routes must have RLS DISABLED — it must be readable before any tenant context exists, to bootstrap Gmail Pub/Sub inbound-webhook routing.');
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    public function test_integration_gmail_mailbox_routes_has_no_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_gmail_mailbox_routes'");

        $this->assertCount(0, $rows);
    }

    /**
     * The one point of genuine divergence from the sibling table's own
     * proof: this table's HMAC column is a KEYED HMAC-SHA256 (never a
     * plain sha256() the way integration_webhook_routing_index's
     * webhook_routing_token_hash is) — a mailbox address is a small,
     * structured, guessable string, unlike an unguessable 256-bit CSPRNG
     * token, so a plain hash here would be trivially dictionary-
     * attackable offline. That distinction is proven at the SERVICE
     * level by GmailMailboxRoutingServiceTest (a parallel writer's own
     * scope, per checkpoint3-design-sync-webhooks.md §10.2) — this test
     * proves the structural, schema-level guarantee: no plaintext
     * mailbox-shaped column exists on the table at all, regardless of
     * how the hash is computed.
     */
    public function test_integration_gmail_mailbox_routes_never_stores_a_plaintext_mailbox_only_the_hmac_and_ciphertext(): void
    {
        $columns = Schema::getColumnListing('integration_gmail_mailbox_routes');

        $this->assertContains('mailbox_lookup_hmac', $columns);
        $this->assertContains('mailbox_display_ciphertext', $columns);

        $this->assertNotContains('mailbox', $columns);
        $this->assertNotContains('mailbox_address', $columns);
        $this->assertNotContains('mailbox_email', $columns);
        $this->assertNotContains('email', $columns);
        $this->assertNotContains('email_address', $columns);

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('plaintext', $column, "Column {$column} must not carry a plaintext-shaped mailbox value.");
            $this->assertStringNotContainsString('secret', $column, "Column {$column} must not carry secret-shaped content — this table holds an HMAC digest and ciphertext only.");
        }
    }

    public function test_the_mailbox_lookup_hmac_column_has_a_global_unique_index(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_gmail_mailbox_routes' and indexdef ilike '%unique%mailbox_lookup_hmac%'"
        );

        $this->assertNotNull($row, 'mailbox_lookup_hmac must carry a GLOBAL unique index — Gmail\'s shared Pub/Sub topic means a mailbox correlator must resolve to at most one active connection platform-wide.');
    }

    public function test_integration_gmail_mailbox_routes_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_gmail_mailbox_routes');
        sort($columns);

        $expected = [
            'id', 'firm_id', 'firm_integration_id', 'integration_provider_id',
            'mailbox_lookup_hmac', 'mailbox_display_ciphertext', 'mailbox_display_encryption_key_id',
            'created_at', 'updated_at',
        ];
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    /**
     * No `status`/soft-disable column — matching
     * integration_webhook_routing_index's own "delete, don't
     * soft-disable" discipline (route()'s delete-before-insert semantics,
     * unroute()'s hard delete): rows exist only while the owning
     * connection's Gmail webhook routing is genuinely active.
     */
    public function test_integration_gmail_mailbox_routes_has_no_status_or_soft_disable_column(): void
    {
        $columns = Schema::getColumnListing('integration_gmail_mailbox_routes');

        $this->assertNotContains('status', $columns);
        $this->assertNotContains('active', $columns);
        $this->assertNotContains('disabled_at', $columns);
        $this->assertNotContains('deleted_at', $columns);
    }

    /**
     * Source-inspection sweep: the table is written/read ONLY by
     * GmailMailboxRoutingService (route()/unroute()/resolveByMailbox()),
     * referenced only in prose by its own return-value data object
     * (ResolvedGmailMailboxRoute — never itself queries the table), and
     * named in the RLS coverage registry's own bookkeeping — no other
     * app/ file may reference the table name at all.
     */
    public function test_integration_gmail_mailbox_routes_is_referenced_only_by_the_authorized_service(): void
    {
        $allowedFiles = [
            'GmailMailboxRoutingService.php',
            // Registry bookkeeping only — records this table's Global/
            // no-RLS classification and disclaimer note; never queries
            // or writes the table itself.
            'RowLevelSecurityCoverageMappingService.php',
            // Documents, in prose only, which table
            // GmailMailboxRoutingService::resolveByMailbox() reads to
            // produce this value object — never itself queries the
            // table.
            'ResolvedGmailMailboxRoute.php',
            // FirmsVault Live Integrations, Checkpoint 4 ("Plaid
            // financial evidence add-on"): PlaidItemRoutingService mirrors
            // GmailMailboxRoutingService's own keyed-lookup design and
            // ResolvedPlaidItemRoute mirrors ResolvedGmailMailboxRoute —
            // both reference `integration_gmail_mailbox_routes` only in
            // docblock prose (design-precedent citations), never in an
            // actual query against that table.
            'PlaidItemRoutingService.php',
            'ResolvedPlaidItemRoute.php',
        ];

        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $basename = basename($file);

            if (in_array($basename, $allowedFiles, true)) {
                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && str_contains($source, 'integration_gmail_mailbox_routes')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'Only GmailMailboxRoutingService may touch integration_gmail_mailbox_routes: '.implode(', ', $violations));
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
