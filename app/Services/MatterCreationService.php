<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterType;
use InvalidArgumentException;
use RuntimeException;

/**
 * MatterCreationService — Firm Feature Manifest §2's confirmed gap:
 * "No general 'create a matter' service exists. Matter::create() is
 * only called from ImportApplyService [a data-migration path] and a
 * non-production pilot workflow service [ProductionPilotWorkflowService::
 * openMatterWithConflictCheck()]." Both call sites duplicate the same
 * required-field/default shape independently; this service centralizes
 * it for a genuine, firm-staff-facing "create a matter" UI action
 * (Tier 3 of the Firm Workspace master mission).
 *
 * Deliberately narrow — creation and opening are two separate concerns
 * (explicit mission instruction: do not conflate them). create() ALWAYS
 * leaves the new Matter in MatterStatus::Draft, mirroring both existing
 * call sites' own default — the $status parameter does not exist here
 * on purpose; a caller cannot pass Open (or any other status) through
 * this service. MatterOpeningService::requestConflictCheck()/openMatter()
 * remains the ONLY path from Draft to Open (per that service's own
 * docblock) — nothing here calls into it or duplicates its
 * conflict-check gating.
 *
 * Ownership-consistency guards, mirroring CalendarEventService::
 * assertBelongsToFirm()'s established fail-closed pattern in this
 * codebase (a narrow consistency check, not new business logic):
 *   - $client must belong to $firm (checked in memory against the
 *     already-loaded model, same as CalendarEventService — no extra
 *     query needed).
 *   - $matterTypeId must belong to $primaryPracticeAreaId (matter
 *     types are scoped under exactly one practice area — PracticeArea/
 *     MatterType are GLOBAL platform catalogs, no BelongsToTenant/RLS,
 *     so this check is a plain query).
 *   - $assignedAttorneyId, if given, and every id in
 *     $assignedStaffUserIds, if given, must each be an ACTIVE FirmUser
 *     of $firm — firm_users is FORCE ROW LEVEL SECURITY, so these
 *     checks run inside an explicit runWithFirmContext() wrap.
 *
 * Role-based authorization (who may call this at all) is deliberately
 * NOT enforced here — that is a Filament-layer concern
 * (MatterCreationAccessPolicyService), matching every other UI-facing
 * *Service in this codebase (LeadConversionService, ManualPaymentService,
 * etc.): application-layer domain services stay UI-agnostic, the
 * *AccessPolicyService classes own the role ceiling.
 */
class MatterCreationService
{
    /**
     * @param  array<int, int>|null  $assignedStaffUserIds  Optional user_ids to attach as active MatterAssignment rows (staffing, separate from the single responsible/assigned attorney).
     */
    public function create(
        Firm $firm,
        Client $client,
        int $primaryPracticeAreaId,
        int $matterTypeId,
        ?int $assignedAttorneyId = null,
        ?string $stage = null,
        ?array $assignedStaffUserIds = null,
    ): Matter {
        $this->assertClientBelongsToFirm($firm, $client);
        $this->assertMatterTypeBelongsToPracticeArea($matterTypeId, $primaryPracticeAreaId);

        if ($assignedAttorneyId !== null) {
            $this->assertUserIsActiveFirmMember($firm, $assignedAttorneyId, 'assigned attorney');
        }

        $staffUserIds = array_values(array_unique(array_map('intval', $assignedStaffUserIds ?? [])));

        foreach ($staffUserIds as $userId) {
            $this->assertUserIsActiveFirmMember($firm, $userId, 'assigned staff member');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm,
            $client,
            $primaryPracticeAreaId,
            $matterTypeId,
            $assignedAttorneyId,
            $stage,
            $staffUserIds,
        ) {
            $matter = Matter::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'primary_practice_area_id' => $primaryPracticeAreaId,
                'matter_type_id' => $matterTypeId,
                'status' => MatterStatus::Draft,
                'stage' => $stage,
                'assigned_attorney_id' => $assignedAttorneyId,
            ]);

            foreach ($staffUserIds as $userId) {
                MatterAssignment::create([
                    'matter_id' => $matter->id,
                    'user_id' => $userId,
                    'assigned_at' => now(),
                ]);
            }

            return $matter->fresh();
        });
    }

    private function assertClientBelongsToFirm(Firm $firm, Client $client): void
    {
        if ((int) $client->firm_id !== (int) $firm->id) {
            throw new RuntimeException(
                "Refusing to create a matter: the given client belongs to firm {$client->firm_id}, not firm {$firm->id}."
            );
        }
    }

    private function assertMatterTypeBelongsToPracticeArea(int $matterTypeId, int $practiceAreaId): void
    {
        $matterType = MatterType::query()->find($matterTypeId);

        if ($matterType === null) {
            throw new InvalidArgumentException("Matter type {$matterTypeId} does not exist.");
        }

        if ((int) $matterType->practice_area_id !== $practiceAreaId) {
            throw new InvalidArgumentException(
                "Matter type {$matterTypeId} does not belong to practice area {$practiceAreaId}."
            );
        }
    }

    private function assertUserIsActiveFirmMember(Firm $firm, int $userId, string $label): void
    {
        $isActiveMember = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): bool => FirmUser::query()
                ->where('firm_id', $firm->id)
                ->where('user_id', $userId)
                ->where('status', FirmUserStatus::Active)
                ->exists(),
        );

        if (! $isActiveMember) {
            throw new RuntimeException(
                "Refusing to create a matter: {$label} (user {$userId}) is not an active member of firm {$firm->id}."
            );
        }
    }
}
