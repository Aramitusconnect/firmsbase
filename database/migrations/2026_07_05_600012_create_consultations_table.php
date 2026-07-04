<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_lead_id')->constrained('firm_leads')->cascadeOnDelete();
            $table->foreignId('consultation_outcome_id')->nullable()->constrained('consultation_outcomes')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('converted')->default(false);

            $table->timestamps();

            $table->index('firm_id');
            $table->index('firm_lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
