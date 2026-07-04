<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_entitlements — module_code is a STRING foreign key to
 * module_catalog.module_code (not module_catalog.id), matching how the
 * app addresses modules everywhere else. unique(firm_id, module_code,
 * source) allows exactly one record per source per module per firm —
 * EntitlementService::resolve() picks the winner across sources by
 * precedence at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('module_code');
            $table->foreign('module_code')->references('module_code')->on('module_catalog')->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->string('source');
            $table->json('settings_json')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['firm_id', 'module_code', 'source']);
            $table->index('firm_id');
            $table->index('module_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_entitlements');
    }
};
