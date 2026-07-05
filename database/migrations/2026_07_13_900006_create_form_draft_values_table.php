<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_draft_values — one row per field per draft. No firm_id column
 * — scoped transitively through form_draft_id (Phase 8 ImportRow
 * precedent). form_mapping_rule_id (nullable — null for
 * manual_override values) is what lets FormReviewService::approve()
 * re-check, precisely and live, that every MAPPED value still traces
 * to a reviewed_approved rule — added specifically to support that
 * exact approval gate, not present in the original v1 manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_draft_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_draft_id')->constrained('form_drafts')->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->foreignId('form_mapping_rule_id')->nullable()->constrained('form_mapping_rules')->restrictOnDelete();

            $table->text('value')->nullable();
            $table->string('source')->default('missing');

            $table->timestamps();

            $table->unique(['form_draft_id', 'form_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_draft_values');
    }
};
