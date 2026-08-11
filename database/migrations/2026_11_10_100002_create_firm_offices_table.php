<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_offices — Mission 2 (MyAttorney Marketplace Core), section 9.
 * Multiple offices per directory_firms row. Global platform data, same
 * RLS-exemption reasoning as directory_firms (see that migration's own
 * docblock and RowLevelSecurityCoverageMappingService::EXEMPT_TABLES).
 *
 * Geography modeled generically (section 4: Michigan-first,
 * nationwide-ready) — country/state/city/postal_code/lat/long, never a
 * Michigan-only shape. `state_normalized`/`city_normalized` exist
 * purely for case/punctuation-insensitive search (section 33), kept as
 * plain indexed columns rather than a DB-level generated column, to
 * stay portable and simple for V1's bounded (Michigan-only) row count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_offices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();

            $table->string('label')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('city_normalized');
            $table->string('state');
            $table->string('country', 2)->default('US');
            $table->string('postal_code');
            $table->string('phone')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('appointment_only')->default(false);
            $table->boolean('published')->default(true);

            $table->string('source_type');
            $table->string('source_reference')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            $table->timestamps();

            $table->index(['directory_firm_id', 'is_primary']);
            $table->index(['city_normalized', 'state']);
            $table->index('published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_offices');
    }
};
