<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_audit_events — append-only audit trail for an import batch's
 * full lifecycle. No uuid (mirrors security_events/
 * platform_billing_events). Written exclusively by ImportAuditService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_audit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('import_batch_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_audit_events');
    }
};
