<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * consultation_outcomes — FIRM-SCOPED (approved decision), same
 * reasoning as lead_sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_outcomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['firm_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_outcomes');
    }
};
