<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_rows — one row per staged source record. raw_data is the
 * untouched source row; mapped_data is the result of applying
 * import_mappings. duplicate_of_type/duplicate_of_id and
 * applied_record_type/applied_record_id are polymorphic pointers (not
 * FK-constrained, since they may point at any of several entity
 * tables) — set by ImportDuplicateDetectionService and
 * ImportApplyService respectively. Carries a uuid (approved) despite
 * potentially high volume per batch, since individual rows are
 * referenced throughout the preview/validate/confirm/rollback
 * workflow, unlike a pure audit-log row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            $table->json('raw_data');
            $table->json('mapped_data')->nullable();

            $table->string('status')->default('staged');
            $table->boolean('is_duplicate')->default(false);
            $table->string('duplicate_of_type')->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();

            $table->string('applied_record_type')->nullable();
            $table->unsignedBigInteger('applied_record_id')->nullable();

            $table->timestamps();

            $table->index(['import_batch_id', 'row_number']);
            $table->index('status');
            $table->index(['duplicate_of_type', 'duplicate_of_id']);
            $table->index(['applied_record_type', 'applied_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
