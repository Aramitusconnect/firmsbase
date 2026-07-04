<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_settings — one per firm. No stripe_enabled column. JSON columns
 * are nullable here; their '{}' default is applied at the application
 * layer (FirmSettings::$attributes) rather than via a raw SQL column
 * default, for driver portability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();

            $table->string('payment_mode')->default('operating_payments_only');
            $table->boolean('trust_iolta_protection')->default(true);
            $table->string('ai_mode')->default('disabled');
            $table->string('client_2fa_mode')->default('optional');
            $table->string('portal_frontend_mode')->nullable();
            $table->string('state_jurisdiction')->nullable();
            $table->string('default_language', 10)->default('en');

            $table->json('branding_settings_json')->nullable();
            $table->json('security_settings_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_settings');
    }
};
