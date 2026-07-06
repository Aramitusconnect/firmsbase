<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_policy_settings — approved decision #6: PLATFORM-LEVEL only, no
 * firm_id column, no BelongsToTenant on the model. Stores platform-
 * wide AI guardrails/defaults (e.g. whether firm_owned mode is
 * globally permitted at all, the canonical high-risk category list)
 * set once by a platform admin, distinct from firm_ai_settings (which
 * is per-firm). Mirrors module_catalog's own "global reference data,
 * not tenant-scoped" reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_policy_settings', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->json('value_json');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_policy_settings');
    }
};
