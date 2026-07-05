<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_sync_events — replaces both a would-be email_sync_state table
 * and a would-be email_audit_events table (approved correction). Pure
 * append-only event log, no uuid. "Current cursor" for an account is
 * derived by querying the latest row where event_type=sync_run and
 * outcome=success, ordered by created_at desc, and reading
 * resulting_cursor — there is no separate mutable state row anywhere.
 *
 * A blocked sync attempt (storage_mode Disabled) still writes exactly
 * one row here with event_type=sync_run and outcome=blocked, so the
 * blocked attempt is auditable even though nothing else was created
 * (approved correction: "may write an email_sync_events row with
 * outcome NotApplicable or Failed/Blocked").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sync_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained('email_accounts')->cascadeOnDelete();

            $table->string('event_type');
            $table->string('outcome');
            $table->string('resulting_cursor')->nullable();
            $table->text('detail')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['email_account_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sync_events');
    }
};
