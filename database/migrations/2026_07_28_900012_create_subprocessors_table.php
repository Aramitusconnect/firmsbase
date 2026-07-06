<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subprocessors — customer-facing disclosure entries linked to
 * vendor_register (approved decision #6). data_categories_json is a
 * declared list (reusing DataCategory enum values); regions_json is a
 * simple declared list of country/region strings, no geolocation logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subprocessors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('vendor_register_id')->constrained('vendor_register')->cascadeOnDelete();
            $table->string('disclosed_name');
            $table->text('service_purpose');
            $table->json('data_categories_json')->nullable();
            $table->json('regions_json')->nullable();

            $table->boolean('is_publicly_disclosed')->default(false);
            $table->timestamp('disclosure_effective_at')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('vendor_register_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subprocessors');
    }
};
