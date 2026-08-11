<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceModerationService — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11 (sections 56-58, 68). The sole write path for
 * a DirectoryFirm's publication_state and is_marketplace_member —
 * mirrors every other Mission 2 workflow service's "one service owns
 * this state machine, every transition is audited" shape, rather than
 * a raw ->update() scattered across Admin Actions.
 *
 * remove() and the membership toggles are the two categories the
 * mission spec explicitly names high-risk ("remove listing", "change
 * member state") — step-up gating for those lives at the Filament
 * Action layer (StepUpAuthentication::protect(), section 57), not
 * here; this service is the audited state-change mechanism both a
 * step-up-gated and a non-step-up-gated caller would go through.
 */
class MarketplaceModerationService
{
    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
        private readonly PlatformAdminAuditEventRecorder $platformAdminAudit = new PlatformAdminAuditEventRecorder,
    ) {}

    public function publish(DirectoryFirm $firm, PlatformAdmin $admin): DirectoryFirm
    {
        return $this->transition($firm, $admin, DirectoryPublicationState::Published, 'marketplace_listing_published', null);
    }

    public function suspend(DirectoryFirm $firm, PlatformAdmin $admin, ?string $reason = null): DirectoryFirm
    {
        return $this->transition($firm, $admin, DirectoryPublicationState::Suspended, 'marketplace_listing_suspended', $reason);
    }

    public function remove(DirectoryFirm $firm, PlatformAdmin $admin, string $reason): DirectoryFirm
    {
        return $this->transition($firm, $admin, DirectoryPublicationState::Removed, 'marketplace_listing_removed', $reason);
    }

    public function archive(DirectoryFirm $firm, PlatformAdmin $admin, ?string $reason = null): DirectoryFirm
    {
        return $this->transition($firm, $admin, DirectoryPublicationState::Archived, 'marketplace_listing_archived', $reason);
    }

    public function activateMembership(DirectoryFirm $firm, PlatformAdmin $admin): DirectoryFirm
    {
        $firm->update(['is_marketplace_member' => true, 'membership_activated_at' => now()]);
        $this->audit($firm, $admin, 'marketplace_membership_activated', []);

        return $firm->fresh();
    }

    public function deactivateMembership(DirectoryFirm $firm, PlatformAdmin $admin, ?string $reason = null): DirectoryFirm
    {
        $firm->update(['is_marketplace_member' => false]);
        $this->audit($firm, $admin, 'marketplace_membership_deactivated', $reason !== null ? ['reason' => $reason] : []);

        return $firm->fresh();
    }

    private function transition(DirectoryFirm $firm, PlatformAdmin $admin, DirectoryPublicationState $state, string $eventType, ?string $reason): DirectoryFirm
    {
        $firm->update(['publication_state' => $state]);
        $this->audit($firm, $admin, $eventType, $reason !== null ? ['reason' => $reason] : []);

        return $firm->fresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(DirectoryFirm $firm, PlatformAdmin $admin, string $eventType, array $metadata): void
    {
        $tenantFirm = $firm->firm()->first();
        $metadata = array_merge(['directory_firm_id' => $firm->id, 'slug' => $firm->slug], $metadata);

        if ($tenantFirm !== null) {
            $this->platformAdminAudit->record($tenantFirm, $admin, $eventType, 'marketplace_moderation', $metadata);

            return;
        }

        $this->tenantContext->runWithoutFirmContext(function () use ($admin, $eventType, $metadata) {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $admin->id,
                'event_type' => $eventType,
                'category' => 'marketplace_moderation',
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }
}
