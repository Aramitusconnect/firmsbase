<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Jobs\RenewGraphSubscriptionJob;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * integrations:webhooks:renew-subscriptions — FirmsVault Live
 * Integrations, Checkpoint 2 (checkpoint2-design-sync-webhooks.md §3.3;
 * checkpoint2-combined-design.md §2 P-20). Layer 1 of a two-layer
 * dispatch loop, in shape mirroring
 * App\Console\Commands\SyncRetryPollCommand — but with one deliberate
 * structural difference, explained below.
 *
 * SyncRetryPollCommand/SweepIntegrationRetentionCommand enumerate the
 * non-RLS `firms` table directly and dispatch exactly one job PER FIRM,
 * leaving that job to do its own per-item work once tenant context is
 * active. This command instead needs to dispatch one
 * RenewGraphSubscriptionJob PER ELIGIBLE SUBSCRIPTION ROW (each job's
 * constructor carries a specific subscriptionId) — that requires
 * knowing which rows are eligible BEFORE dispatch, which in turn
 * requires reading `integration_provider_webhook_subscriptions`, a
 * table under permanent FORCE ROW LEVEL SECURITY (see this
 * checkpoint's own migration pair). A plain, context-free query against
 * a FORCE-RLS table returns zero rows by design — there is no
 * BYPASSRLS/superuser database role this application connects as (see
 * App\Services\PlatformConnectionDirectoryService's own docblock for
 * the identical constraint, independently confirmed against the same
 * table shape). The only architecturally-sound way to read a FORCE-RLS
 * table across every firm from a context-free entry point is the SAME
 * per-firm-loop pattern that class (and
 * App\Services\IntegrationPlatformProviderHealthSummaryService) already
 * establishes: enumerate firms from the non-RLS `firms` table, wrap
 * each iteration in TenantContextService::runWithFirmContext(), and
 * only read/dispatch from inside that context. Still a single, cheap,
 * non-ShouldQueue Artisan command — no job is itself doing this
 * enumeration.
 */
final class RenewProviderWebhookSubscriptionsCommand extends Command
{
    protected $signature = 'integrations:webhooks:renew-subscriptions';

    protected $description = 'Dispatches one RenewGraphSubscriptionJob per active provider webhook subscription nearing its own known expiry.';

    /**
     * Design (checkpoint2-design-sync-webhooks.md §3.3): "renew when
     * now() >= expires_at - min(24h, 20% of (expires_at - created_at))"
     * — a safety margin computed dynamically per subscription row from
     * that row's OWN known lifetime, never a hardcoded assumed
     * duration. Microsoft's exact per-resource subscription lifetime is
     * not fully documented in current research (design's own explicit
     * flag) — this formula is correct regardless of which per-resource
     * duration Graph actually granted, without guessing a number.
     */
    private const MAX_SAFETY_MARGIN_SECONDS = 24 * 60 * 60;

    private const SAFETY_MARGIN_FRACTION = 0.20;

    public function handle(TenantContextService $tenantContext): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(function (int $firmId) use ($tenantContext): void {
                $tenantContext->runWithFirmContext($firmId, function () use ($firmId): void {
                    $this->dispatchDueForFirm($firmId);
                });
            });

        return self::SUCCESS;
    }

    private function dispatchDueForFirm(int $firmId): void
    {
        $now = Carbon::now();

        IntegrationProviderWebhookSubscription::query()
            ->where('firm_id', $firmId)
            ->where('status', ProviderWebhookSubscriptionStatus::Active->value)
            ->get(['id', 'firm_integration_id', 'expires_at', 'created_at'])
            ->each(function (IntegrationProviderWebhookSubscription $subscription) use ($firmId, $now): void {
                if (! $this->isDueForRenewal($subscription, $now)) {
                    return;
                }

                RenewGraphSubscriptionJob::dispatch(
                    $subscription->firm_integration_id,
                    $firmId,
                    $subscription->id,
                );
            });
    }

    private function isDueForRenewal(IntegrationProviderWebhookSubscription $subscription, Carbon $now): bool
    {
        $expiresAt = $subscription->expires_at;
        $createdAt = $subscription->created_at;

        if ($expiresAt === null || $createdAt === null) {
            // Structurally shouldn't happen (both columns are NOT
            // NULL) — fail closed (skip, never guess a margin) rather
            // than risk an unbounded/negative margin computation.
            return false;
        }

        $lifetimeSeconds = abs($expiresAt->getTimestamp() - $createdAt->getTimestamp());
        $marginSeconds = (int) min(self::MAX_SAFETY_MARGIN_SECONDS, $lifetimeSeconds * self::SAFETY_MARGIN_FRACTION);

        return $now->greaterThanOrEqualTo($expiresAt->copy()->subSeconds($marginSeconds));
    }
}
