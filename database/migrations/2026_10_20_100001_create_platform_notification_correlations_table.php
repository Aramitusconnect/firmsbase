<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_notification_correlations — SES event consumer remediation
 * (post-578ee98 audit, finding H1). Solves the same "which account does
 * this inbound SES event belong to" bootstrap problem
 * notification_provider_correlations solves for FIRM-owned mail, for
 * the narrow case where a governed real send (today: password-reset
 * notifications on User/ClientPortalUser) genuinely cannot resolve an
 * owning firm — e.g. a User mid-deactivation, or a ClientPortalUser
 * whose Client record was detached. The original design (578ee98)
 * simply sent these uncorrelated, with no tracking at all; a bounce or
 * complaint on one of these sends could never be suppressed. This
 * table gives that send a durable, tenant-agnostic correlation instead
 * of no correlation.
 *
 * Deliberately NOT RLS-protected, for the identical reason
 * notification_provider_correlations is not: it must be readable by
 * SesEventConsumerService before any firm context can exist, and it
 * carries no notification content, only routing/identity pointers.
 *
 * account_type/account_id is a plain morph pair identifying WHICH
 * User/ClientPortalUser this platform-scope correlation was created
 * for — never used to resolve a firm (that is the entire point of
 * this table's existence), only for operator diagnostics and to let
 * PlatformNotificationCorrelationService's caller re-derive "whose
 * account was this" without touching any firm-scoped table.
 *
 * recipient_fingerprint is a KEYED HMAC-SHA256 of the normalized
 * recipient address — never plaintext. Mirrors
 * App\Integrations\Support\GmailMailboxRoutingService's own "WHY A
 * KEYED HMAC, NOT A PLAIN HASH" reasoning exactly: an email address is
 * a small, structured, often-guessable string, so a plain hash would
 * be dictionary-attackable offline by anyone who obtained a copy of
 * this table. Keyed by a new, dedicated, platform-wide secret
 * (config('services.platform_notifications.recipient_fingerprint_hmac_key')),
 * never APP_KEY, never reused across purposes.
 *
 * provider_message_id is nullable and populated only AFTER the SES
 * send is confirmed successful, identical timing discipline to
 * notification_provider_correlations.provider_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_correlations', function (Blueprint $table) {
            $table->id();

            $table->uuid('correlation_id')->unique();
            $table->string('account_type');
            $table->unsignedBigInteger('account_id');
            $table->string('notification_type');
            $table->string('recipient_fingerprint');
            $table->string('provider_message_id')->nullable()->unique();

            $table->timestamps();

            $table->index(['account_type', 'account_id']);
            $table->index('recipient_fingerprint');
            // provider_message_id already has a unique index from
            // ->unique() above (fixing M2 from the post-578ee98 audit:
            // notification_provider_correlations redundantly declared
            // both ->unique() and ->index() on the same column).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_correlations');
    }
};
