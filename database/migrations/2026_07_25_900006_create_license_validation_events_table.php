<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * license_validation_events — append-only. Every
 * LicenseFileValidationService::validate() call writes exactly one row
 * here, regardless of outcome (project rule: license validation events
 * are logged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_validation_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('license_file_id')->constrained('license_files')->cascadeOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('event_type');
            $table->string('result');
            $table->text('detail')->nullable();
            $table->timestamp('validated_at');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['license_file_id', 'validated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_validation_events');
    }
};
