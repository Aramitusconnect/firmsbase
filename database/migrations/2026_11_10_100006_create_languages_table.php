<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * languages — Mission 2 (MyAttorney Marketplace Core), section 14. A
 * genuinely new global reference table — repository audit confirmed
 * no Language model exists anywhere; `firm_settings.default_language`/
 * `clients.preferred_language` are plain string(10) columns today,
 * left untouched by this migration. Modeled directly on
 * `practice_areas`'s own shape: global platform catalog, no
 * BelongsToTenant, no uuid, addressed by `code` (an ISO 639-1 code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();

            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
