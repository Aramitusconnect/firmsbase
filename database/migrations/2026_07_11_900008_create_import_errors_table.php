<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_errors — no uuid (pure validation-failure log, scoped
 * transitively through import_row_id). Written exclusively by
 * ImportRowValidationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_row_id')->constrained('import_rows')->cascadeOnDelete();
            $table->string('field')->nullable();
            $table->string('severity')->default('error');
            $table->text('message');

            $table->timestamp('created_at')->useCurrent();

            $table->index('import_row_id');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
