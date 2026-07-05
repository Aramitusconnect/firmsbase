<?php

namespace Tests\Feature\Trust\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #1/#14/#15/#16: this phase must not integrate with real
 * bank/payment providers, must not fork/wrap OAuth/webhook/HTTP client
 * code, and must not integrate with Phase 12's accounting/export
 * pipeline (no trust table is ever a candidate for the accounting
 * export). Also enforces the exact 10-table data contract with no
 * extras, and that no new module_catalog migration was added (project
 * rule: no second entitlement system).
 */
class TrustForbiddenIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_STRINGS = [
        'stripe',
        'lawpay',
        'quickbooks',
        'guzzle',
        'Http::',
        'webhook',
        'OAuth',
        'curl_init',
    ];

    private const APPROVED_10_TABLES = [
        'trust_accounts',
        'trust_ledgers',
        'trust_ledger_entries',
        'trust_balances',
        'matter_trust_balances',
        'trust_reconciliations',
        'trust_transfer_requests',
        'trust_refund_requests',
        'trust_approval_events',
        'trust_chargeback_events',
    ];

    private function trustServiceFiles(): array
    {
        return glob(app_path('Services').'/Trust*.php');
    }

    private function trustMigrationFiles(): array
    {
        return glob(database_path('migrations').'/*create_trust_*.php');
    }

    public function test_no_trust_service_references_a_real_payment_provider_or_http_client(): void
    {
        foreach ($this->trustServiceFiles() as $file) {
            $source = file_get_contents($file);

            foreach (self::FORBIDDEN_STRINGS as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $source,
                    basename($file)." must not reference '{$needle}' (no real bank/payment-provider/HTTP integration in this phase)."
                );
            }
        }
    }

    public function test_no_trust_service_references_phase_12_accounting_export_pipeline(): void
    {
        $forbiddenPhase12Symbols = [
            'AccountingExportBatch',
            'AccountingExportLine',
            'ChartOfAccount',
            'AccountingExportLineBuilderService',
        ];

        foreach ($this->trustServiceFiles() as $file) {
            $source = file_get_contents($file);

            foreach ($forbiddenPhase12Symbols as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $source,
                    basename($file)." must not reference Phase 12 accounting export symbol '{$symbol}'."
                );
            }
        }
    }

    public function test_exactly_the_10_approved_trust_tables_have_migrations_and_no_more(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_18_*.php'));
        $tableNamesFound = [];

        foreach ($migrationFiles as $file) {
            foreach (file($file) as $line) {
                if (! str_contains($line, 'Schema::create(')) {
                    continue;
                }

                $after = trim(explode('Schema::create(', $line, 2)[1] ?? '');
                $quote = $after[0] ?? '';

                if (! in_array($quote, ["'", '"'], true)) {
                    continue;
                }

                $rest = substr($after, 1);
                $position = strpos($rest, $quote);

                if ($position === false) {
                    continue;
                }

                $tableNamesFound[] = substr($rest, 0, $position);
            }
        }

        $tableNamesFound = array_values(array_unique($tableNamesFound));
        sort($tableNamesFound);

        $expected = self::APPROVED_10_TABLES;
        sort($expected);

        $this->assertSame($expected, $tableNamesFound);
        $this->assertCount(10, $tableNamesFound);
    }

    public function test_no_forbidden_extra_trust_tables_exist(): void
    {
        $forbidden = [
            'trust_deposit_requests',
            'trust_adjustment_requests',
            'trust_bank_accounts',
            'trust_bank_transactions',
            'trust_payment_provider_accounts',
        ];

        foreach ($forbidden as $tableName) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasTable($tableName),
                "{$tableName} is an explicitly forbidden extra table and must not exist."
            );
        }
    }

    public function test_no_new_module_catalog_migration_was_added_for_trust_iolta(): void
    {
        $moduleCatalogMigrations = glob(database_path('migrations').'/*module_catalog*.php');

        // Phase 13 must reuse the EXISTING entitlement/module_catalog
        // system (project rule: no second entitlement system) — it is
        // only permitted to create the 10 approved trust tables plus
        // read the existing module_catalog/firm_entitlements tables, so
        // no Phase-13-dated module_catalog migration should exist.
        foreach ($moduleCatalogMigrations as $file) {
            $this->assertStringNotContainsString(
                '2026_07_18',
                basename($file),
                'Phase 13 must not add a new module_catalog migration; trust_iolta reuses the existing entitlement system.'
            );
        }
    }
}
