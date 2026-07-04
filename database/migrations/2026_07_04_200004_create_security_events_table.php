<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security_events — append-only audit log. created_at only, no
 * updated_at. No uuid — high-volume internal log, not addressed
 * individually via a public API/route. firm_id is nullable:
 * platform-level events (e.g. a PlatformAdmin action not tied to any
 * one firm) are legitimate rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('event_type');
            $table->string('category');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['actor_type', 'actor_id']);
            $table->index('event_type');
            $table->index('category');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
