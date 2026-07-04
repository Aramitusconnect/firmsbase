<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_licenses — explicitly NO plan_id/org_license_id/billing_mode
 * columns yet. Plans and org-level licensing do not exist until
 * Phase 6; adding these columns now would be unconstrained speculation
 * ahead of that design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('billing_account_id')->nullable()->constrained('billing_accounts')->nullOnDelete();

            $table->string('license_key')->unique();
            $table->string('license_status')->default('trial');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('license_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_licenses');
    }
};
