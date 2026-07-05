<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_rollback_records — one row per applied record that
 * ImportRollbackService can undo. rolled_back_record_type/
 * rolled_back_record_id is a polymorphic pointer mirroring
 * import_rows' applied_record_type/applied_record_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rollback_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained('import_rows')->nullOnDelete();

            $table->string('rolled_back_record_type')->nullable();
            $table->unsignedBigInteger('rolled_back_record_id')->nullable();

            $table->string('status')->default('pending');
            $table->text('reason')->nullable();

            $table->foreignId('rolled_back_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('rolled_back_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();

            $table->timestamps();

            $table->index('import_batch_id');
            $table->index(['rolled_back_record_type', 'rolled_back_record_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rollback_records');
    }
};
