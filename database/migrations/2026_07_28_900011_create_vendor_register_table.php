<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vendor_register — internal vendor/processor governance record
 * (approved decision #6). Table name is intentionally singular-looking
 * ("vendor_register", not "vendor_registers") per the exact required
 * data contract; the Eloquent model is named Vendor with an explicit
 * $table override, not VendorRegister, since each row represents one
 * vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_register', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('vendor_name');
            $table->text('service_purpose');
            $table->string('data_category');
            $table->string('risk_level');
            $table->string('dpa_status');
            $table->string('soc_report_status');
            $table->string('ai_zero_retention_status')->default('not_applicable');

            $table->string('incident_contact_name');
            $table->string('incident_contact_email');
            $table->string('incident_contact_phone')->nullable();

            $table->string('status')->default('active');
            $table->foreignId('added_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamp('added_at');
            $table->timestamp('last_reviewed_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_register');
    }
};
