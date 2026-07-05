<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expense_categories — firm_id is NON-NULLABLE (correction #3: tenant-
 * safe, no platform-global categories in Phase 12). chart_of_accounts_id
 * is nullable; an unmapped category is valid to create, but any expense
 * export line built from it will resolve to a null chart_of_accounts_id
 * and fail at simulation time with an accounting_export_errors row
 * (correction #4), rather than being blocked at category-creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('chart_of_accounts_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['firm_id', 'name']);
            $table->index('chart_of_accounts_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
