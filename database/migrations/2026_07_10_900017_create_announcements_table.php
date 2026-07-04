<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * announcements — targeting columns live directly on the row (no
 * announcement_targets table, approved decision): organization_id,
 * firm_id, plan_id, module_code all nullable, null = broadcast/global.
 * Carries BOTH severity (the announcement's own severity) and
 * min_severity (an optional targeting/filter threshold, e.g. "only show
 * to viewers who opted into at least this severity") — both required
 * per the approved manifest correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('module_code')->nullable();
            $table->string('min_severity')->nullable();

            $table->string('type')->default('general');
            $table->string('severity')->default('info');
            $table->string('status')->default('draft');

            $table->string('title');
            $table->text('body');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index(['starts_at', 'ends_at']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
