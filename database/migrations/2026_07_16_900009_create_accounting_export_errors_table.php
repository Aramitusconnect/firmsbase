<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_export_errors — mirrors Phase 8's import_errors exactly:
 * no uuid, no own firm_id (scoped transitively through
 * accounting_export_line_id), append-only (no updated_at), written
 * exclusively by AccountingExportErrorLogger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_export_errors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('accounting_export_line_id')->constrained('accounting_export_lines')->cascadeOnDelete();

            $table->string('field')->nullable();
            $table->string('severity')->default('error');
            $table->text('message');

            $table->timestamp('created_at')->useCurrent();

            $table->index('accounting_export_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_errors');
    }
};
