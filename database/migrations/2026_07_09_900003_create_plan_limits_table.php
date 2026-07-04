<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan_limits — numeric/enforceable limits per plan (seats by class,
 * storage, AI tokens, API calls). One row per (plan_id, metric).
 * limit_value = null means unlimited for that metric on that plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('metric');
            $table->unsignedBigInteger('limit_value')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
    }
};
