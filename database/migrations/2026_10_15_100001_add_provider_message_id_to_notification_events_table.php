<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SES event consumer (feature/ses-event-consumer) — adds the column an
 * inbound bounce/complaint event uses to resolve back to the exact
 * "sent" notification_events row it corresponds to. Populated only
 * AFTER a real send is confirmed by the mail transport (see
 * OutboundMailCorrelationService) — never before, and never guessed
 * from any other field. Nullable: every pre-existing row, and every row
 * created by a channel other than a real SES send, simply never gets
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_events', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('recipient');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn('provider_message_id');
        });
    }
};
