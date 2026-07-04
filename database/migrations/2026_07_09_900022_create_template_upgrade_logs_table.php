<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_upgrade_logs — firm_id is NOT NULL (genuinely firm-scoped) —
 * this is one of exactly 3 new Phase 6 tables that gets Phase 6 RLS.
 * Append-only: a rollback NEVER mutates or deletes the original Applied
 * row — it inserts a NEW row with status = rolled_back and
 * rollback_of_id pointing back at the row it undoes, mirroring exactly
 * how Phase 5's MaintenanceWindowService::reschedule() and Phase 3's
 * PaymentPlan renegotiation supersede rather than mutate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_upgrade_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('installed_template_pack_id')->constrained('installed_template_packs')->cascadeOnDelete();
            $table->foreignId('from_version_id')->nullable()->constrained('template_pack_versions')->nullOnDelete();
            $table->foreignId('to_version_id')->constrained('template_pack_versions');

            $table->string('status')->default('applied');

            $table->timestamp('applied_at')->useCurrent();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('rollback_of_id')->nullable()->constrained('template_upgrade_logs')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('installed_template_pack_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_upgrade_logs');
    }
};
