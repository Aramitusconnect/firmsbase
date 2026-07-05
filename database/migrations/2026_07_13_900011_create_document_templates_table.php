<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_templates — firm_id NULLABLE: null = global platform
 * default, set = a firm-specific override/custom template. This is
 * the exact pattern Phase 4's NotificationTemplate already uses — not
 * a new design. Dual actor (created_by_firm_user_id / created_by_
 * platform_admin_id), exactly one set, enforced by
 * DocumentTemplateService: firm-specific -> must be a FirmUser of
 * that same firm; global -> must be a PlatformAdmin. No
 * BelongsToTenant (nullable firm_id breaks the "narrow only to
 * firm-owned rows" assumption, same reasoning as Phase 8's ApiKey).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('template_code')->unique();
            $table->string('name');
            $table->string('category')->default('miscellaneous');
            $table->string('status')->default('active');

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
