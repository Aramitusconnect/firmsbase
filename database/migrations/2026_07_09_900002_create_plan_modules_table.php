<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan_modules — which module_catalog modules a plan grants, and
 * whether enabled by default. module_code is a STRING foreign key to
 * module_catalog.module_code, mirroring exactly how firm_entitlements
 * already addresses modules (never module_catalog.id). is_addon
 * distinguishes an optional paid add-on module from a module bundled
 * into the plan's base price — approved decision: add-ons are ordinary
 * plan_modules rows flagged is_addon = true, not a separate table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('module_code');
            $table->foreign('module_code')->references('module_code')->on('module_catalog')->cascadeOnDelete();

            $table->boolean('enabled')->default(true);
            $table->boolean('is_addon')->default(false);
            $table->string('status')->default('active');

            $table->timestamps();

            $table->unique(['plan_id', 'module_code']);
            $table->index('module_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
    }
};
