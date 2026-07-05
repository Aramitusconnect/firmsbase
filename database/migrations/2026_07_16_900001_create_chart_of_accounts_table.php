<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chart_of_accounts — the firm-owned chart-of-accounts foundation.
 * Each firm maintains its own account list (mirrors how a real
 * QuickBooks Online company file has its own chart of accounts); there
 * is no platform-global/shared row (correction #4 — no starter/default
 * COA seed data in Phase 12; firms create rows through
 * ChartOfAccountsService only). account_code is unique per firm, not
 * globally, since two firms may legitimately both use "5010".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('account_code');
            $table->string('account_name');
            $table->string('account_type');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['firm_id', 'account_code']);
            $table->index(['firm_id', 'account_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
