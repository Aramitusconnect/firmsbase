<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting Integrity Hardening Pass, item 2: chart_of_accounts gains
 * a nullable `purpose` column (a ChartOfAccountPurpose case, plain
 * varchar with no database-level check constraint — same convention
 * account_type already uses) so a posting service can resolve exactly
 * ONE canonical account for a specific accounting role (e.g. "the"
 * operating cash account) instead of arbitrarily picking the oldest
 * row of a given account_type.
 *
 * The partial unique index enforces "at most one ACTIVE account per
 * purpose per firm" at the database level — a firm may still deactivate
 * an account and assign the same purpose to a replacement, and may
 * freely have any number of inactive/unassigned rows sharing a purpose
 * value, but two simultaneously active rows can never claim the same
 * purpose. purpose stays nullable indefinitely: a firm's other
 * chart_of_accounts rows (extra bank accounts, category-specific
 * expense accounts already resolved via ExpenseCategory::
 * chart_of_accounts_id) need no purpose assignment at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('purpose')->nullable()->after('account_type');
        });

        DB::statement(
            'CREATE UNIQUE INDEX chart_of_accounts_firm_active_purpose_unique '.
            'ON chart_of_accounts (firm_id, purpose) '.
            'WHERE purpose IS NOT NULL AND is_active = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chart_of_accounts_firm_active_purpose_unique');

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
