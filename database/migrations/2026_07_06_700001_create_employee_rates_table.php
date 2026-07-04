<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * employee_rates — one billing rate and one internal cost rate per
 * employee (project rule: "do not build complex rate tables yet").
 * Effective-dated per approved decision: a rate change closes out the
 * previous row's effective_to and opens a new row, so historical time
 * entries can still be priced/costed using the rate that was active
 * when the work happened (via EmployeeRate::currentRateFor() as of a
 * given date). "Employee" = a platform User acting inside this firm;
 * user_id is a plain bigint FK, no relationship added to User.php.
 *
 * The partial unique index below enforces at most one OPEN-ENDED
 * (effective_to IS NULL) rate per firm+user at the database level —
 * EmployeeRateService is still the only place that opens/closes rows,
 * this is defense-in-depth, same pattern as
 * tenant_encryption_keys_one_active_per_firm from Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('billing_rate_cents');
            $table->unsignedInteger('cost_rate_cents');
            $table->string('currency', 3)->default('usd');

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'user_id']);
            $table->index(['firm_id', 'user_id', 'effective_from']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX employee_rates_one_open_rate_per_employee '.
            'ON employee_rates (firm_id, user_id) WHERE effective_to IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rates');
    }
};
