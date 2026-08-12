<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 13 —
 * "Client Portal handoff + notifications/consent." The prospect's own
 * affirmative consent, captured at submission time (see
 * MarketplaceIntakeService::markSubmitted()) — never fabricated on
 * their behalf by a Firm reviewer at conversion time. Two separate
 * nullable timestamps, not one: a prospect may agree to receive email
 * updates about their case without agreeing to a client portal
 * account, or vice versa — matching the existing ConsentChannel::Email
 * / ConsentChannel::Portal distinction ConsentService already
 * enforces. Null means "not offered/not granted," never "silently
 * assumed." Nullable/additive on the same existing marketplace_intakes
 * table — no new RLS design needed, this table is already FORCE RLS
 * with a non-nullable firm_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->timestamp('communications_consent_at')->nullable()->after('ai_summary_generated_at');
            $table->timestamp('portal_consent_at')->nullable()->after('communications_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->dropColumn(['communications_consent_at', 'portal_consent_at']);
        });
    }
};
