<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * notification_templates — global platform defaults with an optional
 * per-firm override (nullable firm_id; null = platform default,
 * non-null = a firm's custom version), per approved decision.
 *
 * Sender/domain verification fields live HERE, per your explicit
 * clarification — no separate sender_domains table exists anywhere in
 * this migration set (deliberately, to stay inside the approved
 * 15-table Phase 4 data contract). from_email/from_domain/spf_status/
 * dkim_status/dmarc_status/domain_verified_at track a STORED
 * verification outcome only; no live DNS lookups happen anywhere in
 * this phase (approved clarification). channel reuses Phase 1's
 * ConsentChannel enum — no separate NotificationChannel/
 * notification_channels table is created.
 *
 * Two partial unique indexes prevent duplicate templates: at most one
 * global default per (key, channel, language), and at most one
 * firm-specific override per firm per (key, channel, language).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('key');
            $table->string('channel');
            $table->string('language', 10)->default('en');
            $table->string('status')->default('draft');

            $table->string('subject')->nullable();
            $table->text('body');

            $table->string('from_email')->nullable();
            $table->string('from_domain')->nullable();
            $table->string('spf_status')->default('pending');
            $table->string('dkim_status')->default('pending');
            $table->string('dmarc_status')->default('pending');
            $table->timestamp('domain_verified_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('key');
            $table->index('status');
        });

        DB::statement(
            'CREATE UNIQUE INDEX notification_templates_one_global_default '.
            'ON notification_templates (key, channel, language) WHERE firm_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX notification_templates_one_firm_override '.
            'ON notification_templates (firm_id, key, channel, language) WHERE firm_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
