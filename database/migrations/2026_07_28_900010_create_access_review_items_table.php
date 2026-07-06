<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * access_review_items — one reviewed subject per row (an admin, agent,
 * firm user, API key, webhook subscription, AI tool grant, or role).
 * subject_snapshot_json is a declared, informational snapshot of the
 * subject at review time. decision defaults to Pending; a review cannot
 * complete while any item is Pending (AccessReviewService). Revoke/
 * Modify decisions are RECORD-ONLY (approved decision #10) — nothing
 * here executes a real revoke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_review_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('access_review_id')->constrained('access_reviews')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('subject_snapshot_json')->nullable();

            $table->string('decision')->default('pending');
            $table->foreignId('reviewed_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('access_review_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_items');
    }
};
