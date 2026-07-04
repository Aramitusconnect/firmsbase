<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * organizations — optional parent grouping over one or more firms.
 * No default_plan_id column — plans do not exist until Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('status')->default('active');
            $table->string('primary_contact')->nullable();
            $table->string('conflict_scope')->default('firm_scoped');
            $table->string('consolidation_mode')->default('independent');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
