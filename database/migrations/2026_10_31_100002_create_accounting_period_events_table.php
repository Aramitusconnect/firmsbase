<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_period_events — Accounting Integrity Hardening Pass, item
 * 7. AccountingPeriod itself is Phase K's one deliberate exception to
 * this mission's append-only convention (a real mutable status
 * lifecycle, closed -> reopened -> closed again), which already
 * preserves closed_at/closed_by_firm_user_id/reopened_at/
 * reopened_by_firm_user_id/reopen_reason on the row itself (reopen()
 * never clears the closed_* columns). What that row alone cannot
 * guarantee is a truly IMMUTABLE record independent of any future bug
 * in that mutable row — this table is that record, mirroring
 * trust_approval_events' own append-only pattern exactly: one row per
 * transition, never updated or deleted, forming the durable audit
 * trail across however many close/reopen/close-again cycles a period
 * goes through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_period_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->text('reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'accounting_period_id']);
            $table->index(['firm_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_events');
    }
};
