<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_mappings — no firm_id of its own, scoped transitively through
 * import_batch_id (mirrors activation_checklist_items/
 * implementation_tasks precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();

            $table->string('source_field');
            $table->string('target_field');
            $table->string('transform_rule')->nullable();
            $table->boolean('is_required')->default(false);

            $table->timestamps();

            $table->unique(['import_batch_id', 'source_field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
