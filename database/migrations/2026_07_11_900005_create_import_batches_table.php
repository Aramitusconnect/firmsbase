<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_batches — the root of the Import Center workflow: stage,
 * map, dry-run/preview, validate, confirm, apply, rollback. firm_id is
 * non-nullable — this is exactly why ImportBatch (unlike ApiKey) DOES
 * use BelongsToTenant (approved correction #10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('source_type');
            $table->foreignId('migration_project_id')->nullable()->constrained('migration_projects')->nullOnDelete();

            $table->string('status')->default('draft');

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamp('staged_at')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('entity_type');
            $table->index('status');
            $table->index('migration_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
