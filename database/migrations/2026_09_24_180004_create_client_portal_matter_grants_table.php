<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * client_portal_matter_grants — Checkpoint 4 ("Plaid financial evidence
 * add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.6.3;
 * checkpoint4-combined-design.md §1.4's binding naming pass — this
 * table's `client_portal_*` prefix is deliberately unchanged/kept
 * distinct from the unrelated `financial_evidence_matter_*` request/
 * consent/authorization cluster that a later Checkpoint 4 track adds).
 *
 * This is the mechanism that satisfies "a client must only see matters
 * and requests explicitly assigned to that client" — an EXPLICIT grant
 * table, not an inferred "any matter where matters.client_id = this
 * client" rule, because a client may have multiple matters with the
 * firm and not all of them may be portal-visible (e.g. a matter still
 * in ConflictCheckRequired/Draft). Explicit grants let staff control
 * portal visibility per-matter rather than exposing every matter
 * automatically the moment matters.client_id matches.
 *
 * `revoked_at` (rather than deleting the row) preserves grant history,
 * matching `matter_assignments.removed_at`'s established convention.
 * Unique partial index on (client_id, matter_id) WHERE revoked_at IS
 * NULL — at most one currently-active grant per client/matter pair.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard direct-tenant shape
 * (same as `firm_integrations`) — explicit `firm_id` NOT NULL column
 * per the directive's security requirement, even though `client_id`/
 * `matter_id` alone could technically derive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_matter_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
        });

        // Partial unique index: at most one currently-active
        // (non-revoked) grant per (client_id, matter_id) pair. A plain
        // composite unique() including revoked_at would not enforce
        // this — NULL never equals NULL under a standard unique
        // constraint, so multiple simultaneously-active (revoked_at
        // IS NULL) grants for the same pair would not be rejected —
        // this partial index closes that gap explicitly.
        DB::statement(
            'CREATE UNIQUE INDEX client_portal_matter_grants_active_unique '
            .'ON client_portal_matter_grants (client_id, matter_id) '
            .'WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_matter_grants');
    }
};
