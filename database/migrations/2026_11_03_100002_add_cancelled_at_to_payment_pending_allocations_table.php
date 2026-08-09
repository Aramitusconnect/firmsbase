<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending-Cash Accounting pass. Adds cancelled_at (nullable) to
 * payment_pending_allocations for the Cancelled terminal state — see
 * PendingPaymentAllocationStatus's own docblock. Kept separate from
 * resolved_at (which means specifically "an authorized user decided a
 * fee/cost split"): reusing resolved_at for a cancellation would make
 * "every row with resolved_at set was actually resolved" false, a trap
 * for any future report/query built on that assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_pending_allocations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('payment_pending_allocations', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
