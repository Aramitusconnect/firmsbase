<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans — admin-managed commercial plan catalog. Global reference data,
 * no firm_id, no BelongsToTenant. support_access_level is a plain
 * string SETTING on the plan (e.g. "standard"/"priority"/"dedicated"),
 * not a plan_modules row and not a plan_limits metric — approved
 * decision: it is categorical, not an enable/disable module and not a
 * numeric limit. trial_days/trial_requires_card capture "trial rules"
 * directly on the plan; add-ons are modeled as ordinary plan_modules
 * rows with is_addon = true (see create_plan_modules_table), not a
 * separate table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('billing_interval')->default('monthly');
            $table->string('support_access_level')->nullable();
            $table->unsignedInteger('trial_days')->nullable();
            $table->boolean('trial_requires_card')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
