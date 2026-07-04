<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seat_pools — organization-level pooled seats by seat class. Not
 * firm-scoped (organization-owned, not tenant/firm-owned) — no
 * BelongsToTenant, no Phase 6 RLS (approved decision: RLS only for
 * seat_allocations/template_upgrade_previews/template_upgrade_logs).
 * allocated_seats is a running counter maintained by SeatPoolService
 * as seat_allocations rows are created/revoked against this pool —
 * never computed by summing seat_allocations at read time in the hot
 * path, to keep pool-exhaustion checks O(1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_pools', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('seat_class');
            $table->unsignedInteger('total_seats');
            $table->unsignedInteger('allocated_seats')->default(0);
            $table->string('counting_mode')->default('named');
            $table->string('period')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->unique(['organization_id', 'seat_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_pools');
    }
};
