<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\FirmUserAuditEventRecorder;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceCorrectionService — Mission 2 (MyAttorney Marketplace
 * Core), section 51. The ONLY place a DirectoryCorrectionRequest's
 * state changes. Submittable by anyone (an unauthenticated public
 * visitor, or an authenticated FirmUser) — never gated behind claim
 * ownership, since flagging a wrong address or a duplicate listing
 * should not require being the firm's own claimant.
 *
 * Approved and Resolved are deliberately separate transitions
 * (section 51): approve() only records that an admin agrees the
 * report is valid; resolve() is where the actual fix — if any — is
 * applied and where MarketplaceProfileVersionService records it
 * (section 25's versioning table), and only from Approved.
 * `fieldChanges` passed to resolve() is intersected against a fixed
 * public-profile-field allowlist — never an arbitrary column write —
 * so a resolution can never touch is_claimed/firm_id/publication_state/
 * completeness_score or any other non-content column.
 */
class MarketplaceCorrectionService
{
    private const PUBLIC_PROFILE_FIELDS = [
        'display_name', 'phone', 'website', 'public_email', 'description', 'consultation_modes', 'accepting_inquiries',
    ];

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
        private readonly FirmUserAuditEventRecorder $firmUserAudit = new FirmUserAuditEventRecorder,
        private readonly PlatformAdminAuditEventRecorder $platformAdminAudit = new PlatformAdminAuditEventRecorder,
        private readonly MarketplaceProfileVersionService $versions = new MarketplaceProfileVersionService,
    ) {}

    public function submit(
        DirectoryFirm $firm,
        CorrectionType $type,
        string $description,
        ?string $reporterName = null,
        ?string $reporterEmail = null,
        ?FirmUser $reporter = null,
    ): DirectoryCorrectionRequest {
        $request = DirectoryCorrectionRequest::create([
            'directory_firm_id' => $firm->id,
            'correction_type' => $type,
            'state' => CorrectionState::Pending,
            'description' => $description,
            'reporter_name' => $reporterName,
            'reporter_email' => $reporterEmail,
            'reporter_firm_user_id' => $reporter?->id,
        ]);

        $this->auditSubmission($firm, $request, $reporter);

        return $request;
    }

    public function markUnderReview(DirectoryCorrectionRequest $request, PlatformAdmin $admin): DirectoryCorrectionRequest
    {
        return $this->transitionAsAdmin($request, $admin, function (DirectoryCorrectionRequest $locked) {
            if (! in_array($locked->state, [CorrectionState::Pending], true)) {
                throw new \RuntimeException("A correction request in state '{$locked->state->value}' cannot move to under_review.");
            }

            $locked->update(['state' => CorrectionState::UnderReview]);
        }, 'marketplace_correction_under_review');
    }

    public function approve(DirectoryCorrectionRequest $request, PlatformAdmin $admin, ?string $reviewerNotes = null): DirectoryCorrectionRequest
    {
        return $this->transitionAsAdmin($request, $admin, function (DirectoryCorrectionRequest $locked) use ($reviewerNotes) {
            if (! $locked->state->isActive() || $locked->state === CorrectionState::Approved) {
                throw new \RuntimeException("A correction request in state '{$locked->state->value}' cannot be approved.");
            }

            $locked->update(['state' => CorrectionState::Approved, 'decided_at' => now(), 'reviewer_notes' => $reviewerNotes]);
        }, 'marketplace_correction_approved');
    }

    public function reject(DirectoryCorrectionRequest $request, PlatformAdmin $admin, string $reason): DirectoryCorrectionRequest
    {
        return $this->transitionAsAdmin($request, $admin, function (DirectoryCorrectionRequest $locked) use ($reason) {
            if (! $locked->state->isActive()) {
                throw new \RuntimeException("A correction request in state '{$locked->state->value}' cannot be rejected.");
            }

            $locked->update(['state' => CorrectionState::Rejected, 'decided_at' => now(), 'rejection_reason' => $reason]);
        }, 'marketplace_correction_rejected');
    }

    /**
     * @param  array<string, mixed>  $fieldChanges  Intersected against PUBLIC_PROFILE_FIELDS — never an arbitrary write.
     */
    public function resolve(DirectoryCorrectionRequest $request, PlatformAdmin $admin, string $resolutionNotes, array $fieldChanges = []): DirectoryCorrectionRequest
    {
        return $this->transitionAsAdmin($request, $admin, function (DirectoryCorrectionRequest $locked) use ($resolutionNotes, $fieldChanges, $admin) {
            if ($locked->state !== CorrectionState::Approved) {
                throw new \RuntimeException("A correction request in state '{$locked->state->value}' cannot be resolved — only an approved request can be.");
            }

            $allowedChanges = array_intersect_key($fieldChanges, array_flip(self::PUBLIC_PROFILE_FIELDS));

            if ($allowedChanges !== []) {
                $firm = DirectoryFirm::query()->whereKey($locked->directory_firm_id)->lockForUpdate()->firstOrFail();
                $firm->update($allowedChanges);

                $this->versions->record($firm, $allowedChanges, 'platform_admin', $admin->id, DataProvenanceSourceType::AdminEntered);
            }

            $locked->update(['state' => CorrectionState::Resolved, 'decided_at' => now(), 'resolution_notes' => $resolutionNotes]);
        }, 'marketplace_correction_resolved');
    }

    private function auditSubmission(DirectoryFirm $firm, DirectoryCorrectionRequest $request, ?FirmUser $reporter): void
    {
        $tenantFirm = $firm->firm()->first();
        $metadata = [
            'directory_correction_request_id' => $request->id,
            'directory_firm_id' => $firm->id,
            'correction_type' => $request->correction_type->value,
        ];

        if ($reporter !== null && $tenantFirm !== null) {
            $this->firmUserAudit->record($tenantFirm, $reporter->user, 'marketplace_correction_submitted', 'marketplace_correction', $metadata);

            return;
        }

        $this->tenantContext->runWithoutFirmContext(function () use ($metadata) {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => 'public_visitor',
                'actor_id' => null,
                'event_type' => 'marketplace_correction_submitted',
                'category' => 'marketplace_correction',
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }

    private function transitionAsAdmin(DirectoryCorrectionRequest $request, PlatformAdmin $admin, callable $mutate, string $eventType): DirectoryCorrectionRequest
    {
        $firm = DirectoryFirm::query()->whereKey($request->directory_firm_id)->firstOrFail();
        $tenantFirm = $firm->firm()->first();

        $run = function () use ($request, $admin, $mutate, $eventType, $tenantFirm) {
            return DB::transaction(function () use ($request, $admin, $mutate, $eventType, $tenantFirm) {
                $locked = DirectoryCorrectionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

                $mutate($locked);

                $fresh = $locked->fresh();

                $metadata = [
                    'directory_correction_request_id' => $fresh->id,
                    'directory_firm_id' => $fresh->directory_firm_id,
                    'state' => $fresh->state->value,
                ];

                if ($tenantFirm !== null) {
                    $this->platformAdminAudit->record($tenantFirm, $admin, $eventType, 'marketplace_correction', $metadata);
                } else {
                    DB::table('security_events')->insert([
                        'firm_id' => null,
                        'actor_type' => PlatformAdmin::class,
                        'actor_id' => $admin->id,
                        'event_type' => $eventType,
                        'category' => 'marketplace_correction',
                        'metadata' => json_encode($metadata),
                        'created_at' => now(),
                    ]);
                }

                return $fresh;
            });
        };

        return $tenantFirm !== null
            ? $this->tenantContext->runWithFirmContext($tenantFirm, $run)
            : $this->tenantContext->runWithoutFirmContext($run);
    }
}
