<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Models\FirmOffice;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\TenantContextService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceVerificationService — Mission 2 (MyAttorney Marketplace
 * Core), section 24. The ONLY place a DirectoryVerification's state
 * changes. Verification is a deliberate SuperAdmin action taken after
 * reviewing real evidence — never inferred from claim approval alone
 * (section 19: "Claimed Profile" and "Firm Authority Verified" are
 * distinct badges; claiming does not itself grant verification).
 *
 * Audit routing: a verifiable subject often has NO linked tenant Firm
 * at all (an unclaimed listing can be verified from public records
 * before anyone claims it) — security_events is firm-scoped FORCE RLS,
 * so this resolves the subject's linked Firm when one exists (routes
 * through PlatformAdminAuditEventRecorder, matching the claim
 * workflow) and falls back to the established null-firm_id write path
 * (TenantContextService::runWithoutFirmContext(), the exact pattern
 * HighRiskPlatformChangePolicyService::audit() already uses) when it
 * doesn't — never silently skipping the audit event either way.
 */
class MarketplaceVerificationService
{
    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
        private readonly PlatformAdminAuditEventRecorder $platformAdminAudit = new PlatformAdminAuditEventRecorder,
    ) {}

    public function verify(
        Model $verifiable,
        VerificationDimension $dimension,
        PlatformAdmin $admin,
        VerificationSource $source,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): DirectoryVerification {
        $verification = DirectoryVerification::query()->updateOrCreate(
            ['verifiable_type' => $verifiable::class, 'verifiable_id' => $verifiable->id, 'dimension' => $dimension->value],
            [
                'state' => VerificationState::Verified,
                'source' => $source,
                'verified_at' => now(),
                'verified_by_platform_admin_id' => $admin->id,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'revocation_reason' => null,
                'notes' => $notes,
            ],
        );

        $this->audit($verifiable, $admin, 'marketplace_verification_verified', $verification);

        return $verification;
    }

    public function revoke(Model $verifiable, VerificationDimension $dimension, PlatformAdmin $admin, string $reason): DirectoryVerification
    {
        $verification = DirectoryVerification::query()
            ->where('verifiable_type', $verifiable::class)
            ->where('verifiable_id', $verifiable->id)
            ->where('dimension', $dimension->value)
            ->first();

        if ($verification === null || $verification->state !== VerificationState::Verified) {
            throw new \RuntimeException('Only a currently verified dimension can be revoked.');
        }

        $verification->update([
            'state' => VerificationState::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        $this->audit($verifiable, $admin, 'marketplace_verification_revoked', $verification->fresh());

        return $verification->fresh();
    }

    /**
     * Transitions every Verified row whose expires_at has passed into
     * Expired. No admin actor/audit event — a passive, factual state
     * transition, not a decision (mirrors MarketplaceClaimService::
     * expireStaleClaims()'s own reasoning).
     */
    public function expireStale(): int
    {
        return DirectoryVerification::query()
            ->where('state', VerificationState::Verified->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['state' => VerificationState::Expired->value]);
    }

    public function statusFor(Model $verifiable, VerificationDimension $dimension): ?DirectoryVerification
    {
        return DirectoryVerification::query()
            ->where('verifiable_type', $verifiable::class)
            ->where('verifiable_id', $verifiable->id)
            ->where('dimension', $dimension->value)
            ->first();
    }

    public function isVerified(Model $verifiable, VerificationDimension $dimension): bool
    {
        return $this->statusFor($verifiable, $dimension)?->isCurrentlyVerified() ?? false;
    }

    /**
     * Mission 2 checkpoint 14 (performance hardening). Batch
     * counterpart to isVerified() — one query for N subjects instead
     * of N queries, for exactly the case isVerified() itself must
     * never be used in: scoring/rendering a whole search-result list.
     * See MarketplaceBadgeService::badgesForMany()'s own docblock for
     * the N+1 this closes.
     *
     * @param  array<int, int>  $verifiableIds
     * @return array<int, true> verifiable_id => true, for currently-verified subjects only
     */
    public function verifiedIdsAmong(string $verifiableType, array $verifiableIds, VerificationDimension $dimension): array
    {
        if ($verifiableIds === []) {
            return [];
        }

        return DirectoryVerification::query()
            ->where('verifiable_type', $verifiableType)
            ->whereIn('verifiable_id', $verifiableIds)
            ->where('dimension', $dimension->value)
            ->where('state', VerificationState::Verified->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('verifiable_id')
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    private function audit(Model $verifiable, PlatformAdmin $admin, string $eventType, DirectoryVerification $verification): void
    {
        $metadata = [
            'directory_verification_id' => $verification->id,
            'verifiable_type' => $verification->verifiable_type,
            'verifiable_id' => $verification->verifiable_id,
            'dimension' => $verification->dimension->value,
            'state' => $verification->state->value,
        ];

        $firm = $this->resolveLinkedTenantFirm($verifiable);

        if ($firm !== null) {
            $this->platformAdminAudit->record($firm, $admin, $eventType, 'marketplace_verification', $metadata);

            return;
        }

        $this->tenantContext->runWithoutFirmContext(function () use ($admin, $eventType, $metadata) {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $admin->id,
                'event_type' => $eventType,
                'category' => 'marketplace_verification',
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }

    private function resolveLinkedTenantFirm(Model $verifiable): ?Firm
    {
        if ($verifiable instanceof DirectoryFirm) {
            return $verifiable->firm()->first();
        }

        if ($verifiable instanceof FirmOffice) {
            return $verifiable->directoryFirm()->first()?->firm()->first();
        }

        if ($verifiable instanceof DirectoryAttorney) {
            $currentFirm = $verifiable->firmRelationships()->first()?->directoryFirm;

            return $currentFirm?->firm()->first();
        }

        return null;
    }
}
