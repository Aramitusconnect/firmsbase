<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\FirmUserAuditEventRecorder;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceClaimService — Mission 2 (MyAttorney Marketplace Core),
 * sections 20-23. The ONLY place a DirectoryClaim's state changes. Every
 * transition is wrapped in the claimant's real tenant firm context
 * (TenantContextService::runWithFirmContext) — firm_users and
 * security_events both carry FORCE ROW LEVEL SECURITY, so a raw write
 * touching either with no active app.current_firm_id session setting
 * would be rejected by their own WITH CHECK policies — matching
 * PaymentAllocationResolutionService's established
 * "runWithFirmContext(fn () => DB::transaction(...))" shape.
 *
 * Duplicate/conflicting-claim detection (section 21): a second ACTIVE
 * claim from the SAME firm on the SAME listing is rejected outright
 * (RuntimeException — there is nothing new to submit). A second ACTIVE
 * claim from a DIFFERENT firm on the SAME listing is accepted but
 * created directly in Disputed state with conflicts_with_claim_id set
 * — both claims then require explicit SuperAdmin resolution (checkpoint
 * 11), never an automatic winner.
 *
 * approve() never touches any directory_firms column beyond
 * is_claimed/claimed_at/firm_id (section 23: "never silently overwrite
 * unrelated verified data") and auto-rejects every other still-active
 * claim on the same listing with a clear, preserved reason — a
 * claim's full history is never deleted, only state-transitioned.
 */
class MarketplaceClaimService
{
    private const CLAIM_WINDOW_DAYS = 30;

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
        private readonly FirmUserAuditEventRecorder $firmUserAudit = new FirmUserAuditEventRecorder,
        private readonly PlatformAdminAuditEventRecorder $platformAdminAudit = new PlatformAdminAuditEventRecorder,
    ) {}

    public function initiate(DirectoryFirm $directoryFirm, FirmUser $claimant, ?string $claimBasis = null): DirectoryClaim
    {
        if ($directoryFirm->is_claimed) {
            throw new \RuntimeException('This listing has already been claimed. Use the correction/dispute process instead of submitting a new claim.');
        }

        $firm = $claimant->firm()->firstOrFail();

        return $this->tenantContext->runWithFirmContext($firm, function () use ($directoryFirm, $claimant, $firm, $claimBasis) {
            return DB::transaction(function () use ($directoryFirm, $claimant, $firm, $claimBasis) {
                $existingActive = DirectoryClaim::query()
                    ->where('directory_firm_id', $directoryFirm->id)
                    ->whereIn('state', array_map(fn (ClaimState $s) => $s->value, array_filter(ClaimState::cases(), fn (ClaimState $s) => $s->isActive())))
                    ->lockForUpdate()
                    ->get();

                $ownDuplicate = $existingActive->first(fn (DirectoryClaim $c) => (int) $c->firm_id === (int) $firm->id);
                if ($ownDuplicate !== null) {
                    throw new \RuntimeException('Your firm already has an active claim on this listing.');
                }

                $conflictingClaim = $existingActive->first(fn (DirectoryClaim $c) => (int) $c->firm_id !== (int) $firm->id);

                $claim = DirectoryClaim::create([
                    'directory_firm_id' => $directoryFirm->id,
                    'firm_id' => $firm->id,
                    'claimant_firm_user_id' => $claimant->id,
                    'state' => $conflictingClaim !== null ? ClaimState::Disputed : ClaimState::Pending,
                    'claim_basis' => $claimBasis,
                    'conflicts_with_claim_id' => $conflictingClaim?->id,
                    'submitted_at' => now(),
                    'expires_at' => now()->addDays(self::CLAIM_WINDOW_DAYS),
                ]);

                $this->firmUserAudit->record($firm, $claimant->user, 'marketplace_claim_initiated', 'marketplace_claim', [
                    'directory_claim_id' => $claim->id,
                    'directory_firm_id' => $directoryFirm->id,
                    'state' => $claim->state->value,
                    'conflicting_claim_id' => $conflictingClaim?->id,
                ]);

                return $claim;
            });
        });
    }

    public function markUnderReview(DirectoryClaim $claim, PlatformAdmin $reviewer): DirectoryClaim
    {
        return $this->transitionAsAdmin($claim, $reviewer, function (DirectoryClaim $locked) {
            if (! in_array($locked->state, [ClaimState::Pending, ClaimState::EvidenceRequired, ClaimState::Disputed], true)) {
                throw new \RuntimeException("A claim in state '{$locked->state->value}' cannot move to under_review.");
            }

            $locked->update(['state' => ClaimState::UnderReview]);
        }, 'marketplace_claim_under_review');
    }

    public function requireEvidence(DirectoryClaim $claim, PlatformAdmin $reviewer, string $note): DirectoryClaim
    {
        return $this->transitionAsAdmin($claim, $reviewer, function (DirectoryClaim $locked) use ($note) {
            if (! in_array($locked->state, [ClaimState::Pending, ClaimState::UnderReview], true)) {
                throw new \RuntimeException("A claim in state '{$locked->state->value}' cannot be moved to evidence_required.");
            }

            $locked->update(['state' => ClaimState::EvidenceRequired, 'reviewer_notes' => $note]);
        }, 'marketplace_claim_evidence_required');
    }

    public function approve(DirectoryClaim $claim, PlatformAdmin $reviewer): DirectoryClaim
    {
        return $this->transitionAsAdmin($claim, $reviewer, function (DirectoryClaim $locked, PlatformAdmin $reviewer) {
            if (! $locked->state->isActive()) {
                throw new \RuntimeException("A claim in state '{$locked->state->value}' cannot be approved.");
            }

            $directoryFirm = DirectoryFirm::query()->whereKey($locked->directory_firm_id)->lockForUpdate()->firstOrFail();

            if ($directoryFirm->is_claimed) {
                throw new \RuntimeException('This listing was already claimed by another approved claim.');
            }

            $locked->update(['state' => ClaimState::Approved, 'decided_at' => now(), 'decided_by_platform_admin_id' => $reviewer->id]);

            $directoryFirm->update([
                'is_claimed' => true,
                'claimed_at' => now(),
                'firm_id' => $locked->firm_id,
            ]);

            DirectoryClaim::query()
                ->where('directory_firm_id', $locked->directory_firm_id)
                ->where('id', '!=', $locked->id)
                ->whereIn('state', array_map(fn (ClaimState $s) => $s->value, array_filter(ClaimState::cases(), fn (ClaimState $s) => $s->isActive())))
                ->get()
                ->each(function (DirectoryClaim $other) use ($reviewer) {
                    $other->update([
                        'state' => ClaimState::Rejected,
                        'decided_at' => now(),
                        'decided_by_platform_admin_id' => $reviewer->id,
                        'rejection_reason' => 'Another claim on this listing was approved.',
                    ]);
                });
        }, 'marketplace_claim_approved');
    }

    public function reject(DirectoryClaim $claim, PlatformAdmin $reviewer, string $reason): DirectoryClaim
    {
        return $this->transitionAsAdmin($claim, $reviewer, function (DirectoryClaim $locked, PlatformAdmin $reviewer) use ($reason) {
            if (! $locked->state->isActive()) {
                throw new \RuntimeException("A claim in state '{$locked->state->value}' cannot be rejected.");
            }

            $locked->update([
                'state' => ClaimState::Rejected,
                'decided_at' => now(),
                'decided_by_platform_admin_id' => $reviewer->id,
                'rejection_reason' => $reason,
            ]);
        }, 'marketplace_claim_rejected');
    }

    public function revoke(DirectoryClaim $claim, PlatformAdmin $reviewer, string $reason): DirectoryClaim
    {
        return $this->transitionAsAdmin($claim, $reviewer, function (DirectoryClaim $locked) use ($reason) {
            if ($locked->state !== ClaimState::Approved) {
                throw new \RuntimeException("A claim in state '{$locked->state->value}' cannot be revoked — only an approved claim can be.");
            }

            $locked->update([
                'state' => ClaimState::Revoked,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

            $directoryFirm = DirectoryFirm::query()->whereKey($locked->directory_firm_id)->lockForUpdate()->firstOrFail();

            if ((int) $directoryFirm->firm_id === (int) $locked->firm_id) {
                $directoryFirm->update([
                    'is_claimed' => false,
                    'claimed_at' => null,
                    'firm_id' => null,
                ]);
            }
        }, 'marketplace_claim_revoked');
    }

    /**
     * Transitions every still-active claim whose expires_at has passed
     * into Expired. Not wired to a scheduled command in this checkpoint
     * — disclosed, deliberate deferral (see Mission 2 checkpoint 6
     * commit message); callable directly today, and a future checkpoint
     * can register it against the existing scheduler/observability
     * infrastructure (PlatformSchedulerPage) without changing this
     * method's contract.
     */
    public function expireStaleClaims(): int
    {
        $stale = DirectoryClaim::query()
            ->whereIn('state', array_map(fn (ClaimState $s) => $s->value, array_filter(ClaimState::cases(), fn (ClaimState $s) => $s->isActive())))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $claim) {
            $firm = $claim->firm()->firstOrFail();
            $this->tenantContext->runWithFirmContext($firm, function () use ($claim) {
                DB::transaction(function () use ($claim) {
                    DirectoryClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail()
                        ->update(['state' => ClaimState::Expired]);
                });
            });
        }

        return $stale->count();
    }

    private function transitionAsAdmin(DirectoryClaim $claim, PlatformAdmin $reviewer, callable $mutate, string $eventType): DirectoryClaim
    {
        $firm = $claim->firm()->firstOrFail();

        return $this->tenantContext->runWithFirmContext($firm, function () use ($claim, $reviewer, $mutate, $eventType, $firm) {
            return DB::transaction(function () use ($claim, $reviewer, $mutate, $eventType, $firm) {
                $locked = DirectoryClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

                $mutate($locked, $reviewer);

                $fresh = $locked->fresh();

                $this->platformAdminAudit->record($firm, $reviewer, $eventType, 'marketplace_claim', [
                    'directory_claim_id' => $fresh->id,
                    'directory_firm_id' => $fresh->directory_firm_id,
                    'state' => $fresh->state->value,
                ]);

                return $fresh;
            });
        });
    }
}
