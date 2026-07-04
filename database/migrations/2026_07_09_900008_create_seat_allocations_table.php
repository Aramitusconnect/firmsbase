<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seat_allocations — per-firm seat grants, by seat class. firm_id is
 * NOT NULL (this table is genuinely firm-scoped, unlike seat_pools) —
 * this is one of exactly 3 new Phase 6 tables that gets Phase 6 RLS
 * (see extend_row_level_security_to_phase_6_tenant_tables). seat_pool_id
 * nullable: null means a direct firm-level seat grant from the firm's
 * own license/plan, not drawn from any organization pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('seat_pool_id')->nullable()->constrained('seat_pools')->nullOnDelete();
            $table->string('seat_class');
            $table->unsignedInteger('seats_allocated');
            $table->string('status')->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('seat_pool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_allocations');
    }
};
