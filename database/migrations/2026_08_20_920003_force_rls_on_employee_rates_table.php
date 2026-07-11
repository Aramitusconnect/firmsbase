<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3K — Batched FORCE RLS Rollout 02 (3 of 5). Permanently
 * activates FORCE ROW LEVEL SECURITY for employee_rates.
 *
 * EmployeeRateService::setRate()/currentRateFor() now each wrap their
 * own body in runWithFirmContext($firm, ...) directly (the same
 * self-wrapping convention DeadlineService already established), since
 * this service has no production caller today that already establishes
 * context — see the batch report for the full call-site trace
 * (TimeEntryApprovalService::approve(), the one production reader of
 * currentRateFor(), is itself not reachable from any controller,
 * Filament page, job, or command today).
 *
 * Known residual gap, documented and NOT fixed in this batch: nothing
 * in the codebase verifies that the employee_rates.user_id given to
 * setRate() actually holds a firm_users membership row for the target
 * firm. This is a pre-existing business-authorization question,
 * orthogonal to tenant isolation — the policy below still correctly
 * isolates every row by its own firm_id regardless of whether user_id
 * is a legitimate member of that firm — and the existing
 * EmployeeRateServiceTest suite already exercises setRate() for a User
 * with no firm_users tie, so adding enforcement here would be a
 * business-behavior change outside this batch's narrow tenant-context
 * scope. See EmployeeRateService's own docblock and the batch report.
 *
 * EmployeeRateFactory was given the same context-hold create()
 * override every prior FORCE-RLS factory uses so a bare
 * EmployeeRate::factory()->create() keeps working under FORCE.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing this table does not affect firms/users
 * inserts/updates themselves.
 *
 * The down() migration restores only the RLS-enabled-but-not-forced
 * baseline for this one table — it never drops the existing policy or
 * disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'employee_rates';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' NO FORCE ROW LEVEL SECURITY');
    }

    private function quoteIdentifier(string $table): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \RuntimeException("Refusing to activate FORCE RLS on an unsafe/unexpected identifier: {$table}");
        }

        return '"'.$table.'"';
    }
};
