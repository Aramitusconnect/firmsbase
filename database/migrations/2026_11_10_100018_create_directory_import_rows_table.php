<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_import_rows — Mission 2 (MyAttorney Marketplace Core),
 * sections 53-55. See directory_import_batches' own docblock for why
 * this is a parallel table rather than a reuse of the generic
 * `import_rows`. `raw_data` preserves exactly what was parsed from the
 * CSV (already formula-injection-neutralized on ingestion — see
 * MarketplaceCsvIngestionService); `mapped_data` is the validated,
 * column-mapped result MarketplaceImportValidationService produces.
 * `duplicate_of_directory_firm_id` records a real candidate match
 * (section 52) — never auto-merged; `applied_directory_firm_id`
 * records the actual outcome once MarketplaceImportApplyService runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('directory_import_batch_id')->constrained('directory_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            $table->json('raw_data');
            $table->json('mapped_data')->nullable();
            $table->string('status')->default('pending');
            $table->json('errors')->nullable();

            $table->foreignId('duplicate_of_directory_firm_id')->nullable()->constrained('directory_firms')->nullOnDelete();
            $table->foreignId('applied_directory_firm_id')->nullable()->constrained('directory_firms')->nullOnDelete();

            $table->timestamps();

            $table->index(['directory_import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_import_rows');
    }
};
