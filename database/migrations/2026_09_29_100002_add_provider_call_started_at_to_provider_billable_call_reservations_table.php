<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `provider_call_started_at` to `provider_billable_call_reservations`
 * — the ONE piece of state the reserve/finalize machine was missing to
 * tell the two crash windows apart when a later attempt re-encounters an
 * abandoned reservation for the SAME idempotency key:
 *
 *   window A — crashed AFTER `reserve()` but BEFORE the outbound call
 *              ever left this process. The provider was provably never
 *              contacted, so nothing was billed and a fresh attempt is
 *              unambiguously safe.
 *   window B — crashed AFTER the outbound call started. Whether the
 *              provider received/processed (and therefore billed) the
 *              request is genuinely unknowable from here.
 *
 * Without this column both windows look identical (`status = 'reserved'`
 * with an elapsed `expires_at`), so
 * `App\Integrations\Billing\ProviderBillableCallPipeline` would have to
 * pick one of two bad defaults for BOTH: never retry (a single crash
 * wedges the logical operation forever, because the idempotency key is
 * now deterministic and the unique index refuses a second row) or always
 * retry (re-fires a call that may already have been billed). One
 * nullable timestamp, written once immediately before `$providerCall()`
 * runs, collapses window A to "definitely safe" and leaves only window B
 * — the genuinely ambiguous one — treated conservatively as uncertain.
 *
 * Deliberately NOT a lease/ownership token (`leased_by` + `leased_at`):
 * ownership is already established atomically by the existing
 * `(firm_integration_id, idempotency_key)` unique index plus the
 * compare-and-set UPDATE in
 * `ProviderUsageReservationService::reclaim()`, so a second column
 * carrying a worker identity would add state without adding a guarantee.
 *
 * Nullable with no backfill and no index: every pre-existing row is
 * correctly `NULL` (its outbound call predates this column, and those
 * rows are all long past their TTL and already swept to `expired`), and
 * the column is only ever read by primary-key lookup on a row the
 * pipeline already holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_billable_call_reservations', function (Blueprint $table) {
            $table->timestamp('provider_call_started_at')->nullable()->after('reserved_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_billable_call_reservations', function (Blueprint $table) {
            $table->dropColumn('provider_call_started_at');
        });
    }
};
