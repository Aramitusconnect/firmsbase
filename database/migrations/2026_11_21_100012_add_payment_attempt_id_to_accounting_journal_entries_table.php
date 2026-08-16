<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_journal_entries.payment_attempt_id — FirmsVault Pay Gate
 * A2 (v1.4 §35, "JournalEntry -> new payment posting link").
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT. The journal header already links
 * to payment_id / invoice_id / expense_id / trust_transfer_request_id,
 * but a provider capture posts BEFORE (and possibly without) any
 * canonical Payment row: the capture's cash leg is a processor clearing
 * balance, not received bank cash. Without this column a provider
 * capture entry would have no traceable source, breaking the existing
 * "every journal entry names its source" convention.
 *
 * FOREIGN KEY STRATEGY — deliberately different from its siblings. The
 * existing source links on this table are PLAIN foreign keys, which
 * Gate A1 identified as a real (pre-existing) tenant-consistency gap:
 * a raw insert can name another firm's payment_id. v1.4 §55 forbids
 * broadly refactoring that legacy domain during this POC, so those
 * columns are left exactly as they are — but §35 requires every NEW
 * relationship on the FirmsVault Pay path to have database-enforced
 * tenant consistency. This column therefore uses a COMPOSITE FK
 * (payment_attempt_id, firm_id) -> payment_attempts (id, firm_id),
 * making a Firm A journal entry against a Firm B attempt structurally
 * impossible rather than merely unlikely.
 *
 * ROLLBACK. Drops the column and its constraint only; no existing data
 * is touched, since every pre-existing row has NULL here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_attempt_id')->nullable()->after('payment_id');
            $table->index('payment_attempt_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE accounting_journal_entries
            ADD CONSTRAINT accounting_journal_entries_payment_attempt_same_firm_fk
            FOREIGN KEY (payment_attempt_id, firm_id)
            REFERENCES payment_attempts (id, firm_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE accounting_journal_entries DROP CONSTRAINT IF EXISTS accounting_journal_entries_payment_attempt_same_firm_fk');

        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->dropIndex(['payment_attempt_id']);
            $table->dropColumn('payment_attempt_id');
        });
    }
};
