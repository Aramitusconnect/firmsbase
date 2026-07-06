<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * access_reviews — a periodic review campaign scoped to platform
 * admins, support agents, firm admins, API keys, webhooks, AI tools, or
 * employee roles. firm_id is null for platform-scope reviews (e.g.
 * platform_admins), set for firm-scope reviews (e.g. firm_admins,
 * api_keys for one firm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('scope');
            $table->string('status')->default('draft');

            $table->foreignId('initiated_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamp('initiated_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');
    }
};
