<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_billing_events — append-only platform billing audit trail,
 * keyed to billing_account_id. event_type is a plain string (project
 * convention for event-log tables, explicit instruction). No
 * PlatformBillingEventStatus enum exists — this table has no status
 * column at all, mirroring firm_activation_events/incident_events/
 * status_page_events from Phase 5. No uuid — internal audit trail,
 * accessed only through its parent billing account, matching
 * firm_activation_events' precedent exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->string('event_type');
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('billing_account_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_events');
    }
};
