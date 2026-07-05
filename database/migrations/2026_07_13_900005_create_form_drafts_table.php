<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_drafts — the firm-owned workflow root. form_template_version_id
 * is immutable after creation (enforced in the FormDraft model, mirrors
 * HasPublicUuid's uuid-immutability guard) — this is what makes
 * "historical drafts must retain form_template_version_id" hold
 * regardless of any later retirement. status uses the exact 8 approved
 * values (see FormDraftStatus); used_sample_mapping is recomputed live
 * at approval time by FormReviewService, not trusted as a one-time
 * snapshot. generated_by_firm_user_id is non-nullable — draft
 * generation is always a firm action, never platform-staff-assisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('form_template_version_id')->constrained('form_template_versions')->restrictOnDelete();

            $table->string('status')->default('draft');
            $table->boolean('used_sample_mapping')->default(false);

            $table->foreignId('generated_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->foreignId('reviewed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'matter_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
    }
};
