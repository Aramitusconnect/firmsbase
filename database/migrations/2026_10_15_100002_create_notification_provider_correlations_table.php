<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_provider_correlations — SES event consumer
 * (feature/ses-event-consumer). Solves a real bootstrap problem: an
 * inbound SES bounce/complaint notification arrives with only a
 * provider message ID and a recipient address, and this application's
 * hard rule is "never resolve a tenant using recipient address alone"
 * (the same email can legitimately exist across multiple firms).
 * notification_events itself cannot answer "which firm does this
 * message ID belong to" without already knowing the firm, because it
 * carries permanent FORCE ROW LEVEL SECURITY
 * (2026_08_25_930024_force_rls_on_notification_events_table.php) — the
 * exact same problem firm_users' own narrow self-lookup RLS clause
 * (2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php)
 * and User::activeFirmUser() solve for an authenticating user.
 *
 * Deliberately NOT RLS-protected, and deliberately a separate table
 * from notification_events rather than a widened RLS policy on it:
 * this table stores no notification content whatsoever (no subject,
 * no body, no template, no reason) — only routing pointers (which firm
 * a given correlation/provider message belongs to). RLS-protecting a
 * table whose entire purpose is "resolve the firm before any tenant
 * context exists" would reintroduce the identical bootstrap problem it
 * exists to solve. Every actual business-data write triggered by a
 * resolved row here (SuppressionService::recordBounce()/
 * recordComplaint()) still goes through notification_events under a
 * correctly-established runWithFirmContext($firm, ...) call — this
 * table only ever answers "which firm", never "what happened".
 *
 * correlation_id is the opaque UUID tagged on the outbound SES message
 * (via Symfony's MetadataHeader, which Illuminate\Mail\Transport\
 * SesTransport translates into an SES message Tag) — never a
 * sequential ID, so nothing overturned this rule about avoiding
 * exposed sequential identifiers.
 *
 * provider_message_id is nullable and only populated AFTER the SES
 * send is confirmed successful (Illuminate\Mail\Events\MessageSent),
 * matching the explicit rule against recording anything before SES
 * itself has accepted the message. It is the PRIMARY, authoritative
 * resolution key for inbound events — never mail.tags from the SES
 * event payload itself, which this application's own consumer treats
 * as, at most, an unvalidated hint (see SesEventConsumerService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_provider_correlations', function (Blueprint $table) {
            $table->id();

            $table->uuid('correlation_id')->unique();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('channel');
            $table->string('recipient_normalized');
            $table->string('provider_message_id')->nullable()->unique();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_provider_correlations');
    }
};
