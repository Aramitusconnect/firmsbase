<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_external_mappings — Checkpoint 6, third table of the
 * six-table date block (reviews/checkpoint-06/frozen-design-post-review.md
 * §3/§6/§8). Direct firm-owned, the local<->external identity bridge:
 * the durable, long-retention (never hard-deleted) 1:1-per-connection
 * pointer between a FirmsBase-side record and a provider-side object.
 *
 * `local_type`/`local_id`/`external_id` — frozen column naming
 * (frozen-design-post-review.md §3), matching this codebase's real
 * polymorphic convention, not `local_resource_type`/`local_resource_id`
 * or a fourth "external_resource_type" axis (rejected as speculative —
 * no registered provider needs a provider-native type distinct from
 * `resource_type`).
 *
 * Idempotency/collision-prevention (frozen-design-post-review.md §6):
 * two partial unique indexes, both required, BOTH LEADING ON
 * `firm_integration_id` (never `firm_id` alone) — this is what
 * prevents two connections of the SAME firm from conflating identical
 * external IDs, a case where RLS provides zero protection (both rows
 * share the same firm_id; only firm_integration_id, backed by
 * firm_integrations.id's own PK guarantee, distinguishes them).
 * `WHERE tombstoned_at IS NULL` on both — a tombstoned mapping never
 * blocks a fresh live mapping for the same local-or-external object
 * from being created, while the historical row survives for audit.
 *
 * Tombstoning (frozen-design-post-review.md §8, adopting
 * agent-6f-mapping-conflict-design.md's backward-compatible
 * generalization of the frozen domain-model doc's original narrower
 * `deleted_externally_at`): `tombstoned_at` + `tombstone_reason` (4
 * reasons — external_deleted, local_deleted, connection_disconnected,
 * superseded_by_reconnect). This table has no delete()/forceDelete()
 * application code path — only the tombstone write.
 *
 * Retention: permanent, never hard-deleted — living state, not an
 * append-only log. No retention columns/index beyond the tombstone
 * predicate itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_external_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('resource_type');
            $table->string('local_type');
            $table->unsignedBigInteger('local_id');
            $table->string('external_id');

            $table->string('external_version_token')->nullable();
            $table->string('local_version_token')->nullable();

            $table->string('sync_direction');
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamp('tombstoned_at')->nullable();
            $table->string('tombstone_reason')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_external_mappings_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX integration_external_mappings_local_unique '.
            'ON integration_external_mappings (firm_integration_id, resource_type, local_type, local_id) '.
            'WHERE tombstoned_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX integration_external_mappings_external_unique '.
            'ON integration_external_mappings (firm_integration_id, resource_type, external_id) '.
            'WHERE tombstoned_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_external_mappings');
    }
};
