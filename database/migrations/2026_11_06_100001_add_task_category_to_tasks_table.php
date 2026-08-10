<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leverage Ratio Optimizer, item 6. Task had no categorization concept
 * at all before this pass (confirmed by audit). task_category is
 * nullable and closed to TaskWorkCategory's own vocabulary — set
 * explicitly at creation time (by a firm user, or by an Automation
 * action's own config), NEVER inferred from title/description. A task
 * with no category is simply excluded from task-role mismatch
 * analysis, never guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('task_category')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('task_category');
        });
    }
};
