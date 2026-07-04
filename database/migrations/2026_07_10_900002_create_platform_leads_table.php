<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_leads — PLATFORM sales pipeline leads only (a prospective
 * law firm considering FirmsBase). Deliberately named platform_leads,
 * NOT leads, to avoid ambiguity with Phase 2's firm_leads (client
 * intake leads owned by a firm). firm_leads is never reused or
 * referenced here. converted_organization_id is set ONLY by
 * ConversionEventService at the moment of conversion, mirroring
 * firm_leads' own converted_client_id discipline. Not RLS-prepared,
 * no BelongsToTenant — platform-staff-owned, cross-firm by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('company_name');
            $table->string('contact_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('source')->nullable();

            $table->string('status')->default('new');

            $table->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->foreignId('converted_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_leads');
    }
};
