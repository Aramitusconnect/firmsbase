<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_missing_data_items — one row per detected missing required
 * field. No firm_id — scoped transitively through form_draft_id.
 * resolved_at is set automatically by FormMissingDataDetectionService
 * on a re-scan that finds the field now populated — no separate human
 * actor column is needed for resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_missing_data_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_draft_id')->constrained('form_drafts')->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained('form_fields')->cascadeOnDelete();

            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['form_draft_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_missing_data_items');
    }
};
