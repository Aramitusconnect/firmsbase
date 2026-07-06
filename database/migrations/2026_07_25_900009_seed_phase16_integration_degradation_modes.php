<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php), mirroring the EXISTING Phase 14/15 module_catalog
 * seed pattern exactly. Idempotently upserts exactly the 4 approved
 * integration_degradation_modes rows, keyed on integration_type.
 * Re-running this migration never creates a duplicate row and never
 * errors on a second run.
 */
return new class extends Migration
{
    private array $modes = [
        ['integration_type' => 'stripe', 'degraded_behavior' => 'queue_and_retry', 'description' => 'Stripe unavailable: queue payment actions and retry; never silently drop a payment attempt.'],
        ['integration_type' => 'email_provider', 'degraded_behavior' => 'queue_and_retry', 'description' => 'Email provider unavailable: queue outbound messages and retry; never silently drop a notification.'],
        ['integration_type' => 'virus_scanning', 'degraded_behavior' => 'block_action', 'description' => 'Virus scanner unavailable: block the upload action entirely; never accept an unscanned document.'],
        ['integration_type' => 'telemetry', 'degraded_behavior' => 'fallback_local', 'description' => 'Telemetry unavailable or prohibited: fall back to a local, offline health report; never block application use.'],
    ];

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $mode) => array_merge($mode, [
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->modes
        );

        DB::table('integration_degradation_modes')->upsert(
            $rows,
            ['integration_type'],
            ['degraded_behavior', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('integration_degradation_modes')
            ->whereIn('integration_type', array_column($this->modes, 'integration_type'))
            ->delete();
    }
};
