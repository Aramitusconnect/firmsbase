<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('platform_lead_id')->constrained('platform_leads')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->string('status')->default('open');
            $table->unsignedInteger('estimated_seats')->nullable();
            $table->unsignedInteger('estimated_mrr_cents')->nullable();
            $table->timestamp('expected_close_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('platform_lead_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
