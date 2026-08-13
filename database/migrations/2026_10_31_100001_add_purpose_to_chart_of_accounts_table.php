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
 *
 * Staging-safety review (pre-deploy, migration never yet applied
 * anywhere real): the unique index is built CONCURRENTLY so it never
 * takes a write-blocking lock on chart_of_accounts — a pre-existing,
 * populated table — for the build's duration. CREATE INDEX
 * CONCURRENTLY cannot run inside a transaction, hence
 * $withinTransaction = false (see Illuminate\Database\Migrations\
 * Migration's own doc comment). The read-only preflight below proves
 * no (firm_id, purpose) duplicate exists among active rows before the
 * index is attempted; it is expected to always pass today since
 * `purpose` is a brand-new column with no backfill (every row is
 * NULL immediately after the column is added, and Postgres never
 * treats two NULLs as duplicates) — kept anyway as a hard safety net
 * in case this migration is ever re-run somewhere `purpose` has
 * already been populated out of band.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('purpose')->nullable()->after('account_type');
        });

        $duplicates = DB::table('chart_of_accounts')
            ->select('firm_id', 'purpose', DB::raw('COUNT(*) as count'))
            ->whereNotNull('purpose')
            ->where('is_active', true)
            ->groupBy('firm_id', 'purpose')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to create chart_of_accounts_firm_active_purpose_unique: '.
                $duplicates->count().' (firm_id, purpose) combination(s) already have '.
                'more than one active row. Resolve the duplicates manually — this '.
                'migration will not delete or merge data automatically. First '.
                'conflicting rows: '.$duplicates->take(5)->toJson()
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX CONCURRENTLY chart_of_accounts_firm_active_purpose_unique '.
            'ON chart_of_accounts (firm_id, purpose) '.
            'WHERE purpose IS NOT NULL AND is_active = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS chart_of_accounts_firm_active_purpose_unique');

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
