<?php

namespace App\Console\Commands;

use App\Marketplace\Services\MarketplaceClaimService;
use Illuminate\Console\Command;

/**
 * marketplace:claims:expire-stale — Mission 2 (MyAttorney Marketplace
 * Core), sections 20-23. Same "no real mutation call site to hook
 * into, so sweep" shape as SweepTaskOverdueStatusCommand:
 * `MarketplaceClaimService::expireStaleClaims()` already exists, is
 * idempotent (only ever moves a still-active claim whose own
 * `expires_at` has already passed into Expired; a claim with no
 * expires_at, or one already Approved/Rejected/Revoked/Expired, is
 * simply not touched — confirmed by direct source read), and
 * correctly derives staleness from `expires_at` — it was simply never
 * called by anything in production (confirmed by the method's own
 * docblock, which explicitly discloses this as a deliberate deferral
 * from a prior checkpoint). This command is the first real caller.
 *
 * Deliberately narrower than the claim review workflow itself: this
 * command only ever transitions a claim into Expired — it never
 * approves, rejects, or otherwise decides one — so re-running this
 * sweep can never regress a claim that a SuperAdmin has already acted
 * on, or one that is not yet past its own claim window.
 */
final class ExpireStaleMarketplaceClaimsCommand extends Command
{
    protected $signature = 'marketplace:claims:expire-stale';

    protected $description = 'Transitions every still-active DirectoryClaim whose claim window has expired into the Expired state.';

    public function __construct(
        private readonly MarketplaceClaimService $claims,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->claims->expireStaleClaims();

        return self::SUCCESS;
    }
}
