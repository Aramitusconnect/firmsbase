<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: adds default_plan_id to the EXISTING organizations
 * table (created in Phase 1). Does not recreate organizations. Phase
 * 1's migration comment explicitly deferred this column to Phase 6 —
 * "plans do not exist until Phase 6."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('default_plan_id')->nullable()->after('consolidation_mode')
                ->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_plan_id');
        });
    }
};
