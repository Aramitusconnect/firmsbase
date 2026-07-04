<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_entitlement_events — append-only audit trail. No uuid,
 * UPDATED_AT disabled at the model layer, created_at only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_entitlement_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_entitlement_id')->constrained('firm_entitlements')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('module_code');

            $table->string('source');
            $table->string('action');
            $table->text('reason')->nullable();

            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_entitlement_id');
            $table->index('firm_id');
            $table->index('module_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_entitlement_events');
    }
};
