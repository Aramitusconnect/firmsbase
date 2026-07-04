<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * release_notes — platform-level only. Deliberately carries NO
 * organization_id/firm_id/plan_id column — release notes must never be
 * tied to firm legal data, per the approved Phase 7 scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('version')->nullable();
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_notes');
    }
};
