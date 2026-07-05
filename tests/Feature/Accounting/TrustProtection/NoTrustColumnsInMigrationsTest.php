<?php

namespace Tests\Feature\Accounting\TrustProtection;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: Phase 12 migrations do not add trust account / trust
 * ledger / trust transaction / trust reservation columns anywhere.
 */
class NoTrustColumnsInMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'trust_account', 'trust_ledger', 'trust_transaction', 'trust_reservation',
        'trust_iolta', 'iolta',
    ];

    public function test_no_phase_12_migration_references_any_trust_concept(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_16_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 12 migration files to be present.');

        foreach ($migrationFiles as $file) {
            $source = strtolower(file_get_contents($file));

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file)." must not reference: {$needle}");
            }
        }
    }
}
