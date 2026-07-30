<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Models\Firm;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireStaleProviderReservationsJob — the reservation/finalization
 * state machine's expiry-sweep job (checkpoint4-design-cost-control.md
 * §3.3/§3.2). Run every few minutes, transitions every
 * `reserved` -> `expired` row whose `expires_at` has passed (a crashed
 * worker between reserve and finalize, never auto-resolved further —
 * matching the trust domain's own "a discrepancy is never
 * auto-corrected" rule verbatim). `expired` rows are surfaced only via
 * the anomaly surface and monthly reconciliation, never retried
 * automatically by this or any other job.
 *
 * `provider_billable_call_reservations` is Direct `BelongsToTenant` +
 * FORCE RLS, so a platform-wide sweep — like
 * `App\Services\ProviderInvoiceReconciliationService::run()` — must
 * iterate every firm under its own `runWithFirmContext()` scope rather
 * than issuing one unscoped cross-firm query (which would silently
 * match zero rows under FORCE RLS with no session context active). This
 * mirrors `App\Jobs\RetentionSweepJob`'s own established per-firm-scoped
 * discipline for platform-wide sweep work.
 */
final class ExpireStaleProviderReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('expire-stale-provider-reservations'))->releaseAfter(60)->expireAfter(1800),
        ];
    }

    public function handle(ProviderOperationAttemptService $operationAttempts): void
    {
        // The durable gate's own lease sweep. Bounded, context-free, and
        // deliberately first: it only ever moves a row to a state that
        // forbids an automated re-send (`retry_allowed` for a provably
        // un-sent claim, `provider_outcome_uncertain` for an abandoned
        // in-flight one), so it can never authorize a call.
        $operationAttempts->sweepExpiredLeases();

        Firm::query()->select('id')->orderBy('id')->chunkById(200, function ($firms) {
            foreach ($firms as $firm) {
                $firmId = $firm->id;

                // runInFirmContext() ultimately calls
                // TenantContextService::runWithFirmContext(Firm|int|string
                // $firm, ...), which re-fetches a full Firm internally
                // when given a plain id -- passing the partial model
                // selected above (only ->select('id')) directly throws,
                // since that internal re-fetch/attribute access needs
                // columns (uuid/organization_id/deployment_mode) this
                // partial model never loaded. Mirrors the established,
                // working precedent in
                // RenewProviderWebhookSubscriptionsCommand::handle(),
                // which enumerates firms via ->pluck('id') and passes the
                // raw id, never a partial model, for the identical reason.
                $this->runInFirmContext($firmId, function () use ($firmId) {
                    ProviderBillableCallReservation::query()
                        ->where('firm_id', $firmId)
                        ->where('status', ProviderBillableCallReservation::STATUS_RESERVED)
                        ->where('expires_at', '<', now())
                        ->chunkById(200, function ($reservations) {
                            foreach ($reservations as $reservation) {
                                $reservation->forceFill([
                                    'status' => ProviderBillableCallReservation::STATUS_EXPIRED,
                                    'finalized_at' => now(),
                                ])->save();
                            }
                        });
                });
            }
        });
    }
}
