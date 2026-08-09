<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the durable idempotency mechanism Phase D (operating-event
 * journal wiring) requires: business events (payment applied, expense
 * paid, trust transfer, refund, etc.) can legitimately be retried by
 * their own callers (webhook redelivery, queued-job retry), and a
 * retry must never double-post. AccountingJournalPostingService::post()
 * short-circuits to the existing entry when a caller-supplied
 * idempotency_key already exists for the firm, instead of posting a
 * second entry. Nullable because not every caller supplies one (e.g.
 * a manually-triggered adjustment has no natural retry key); the
 * partial unique index only constrains non-null keys, mirroring
 * payments.idempotency_key's own partial-unique pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('source_type');
        });

        DB::statement(
            'create unique index accounting_journal_entries_firm_idempotency_key_unique '.
            'on accounting_journal_entries (firm_id, idempotency_key) '.
            'where idempotency_key is not null'
        );
    }

    public function down(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            DB::statement('drop index if exists accounting_journal_entries_firm_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
