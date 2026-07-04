<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * license_events — append-only audit trail covering BOTH firm_licenses
 * and org_licenses transitions in one table (per the approved data
 * contract's single "license_events" entry), via a polymorphic
 * licensable_type/licensable_id pair rather than two nullable FK
 * columns. event_type is a plain string (project convention for
 * event-log tables, per your explicit instruction). No uuid — this is
 * an internal audit log, not a public-facing record, mirroring
 * firm_entitlement_events' precedent. Deliberately excluded from Phase
 * 6 RLS: its ownership is mixed (some rows are firm-scoped via
 * firm_licenses, others are organization-scoped via org_licenses), so a
 * single firm_id-keyed RLS policy cannot safely cover it — the same
 * reasoning Phase 5 used to exclude status_page_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_events', function (Blueprint $table) {
            $table->id();

            $table->string('licensable_type');
            $table->unsignedBigInteger('licensable_id');

            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();

            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['licensable_type', 'licensable_id']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
    }
};
