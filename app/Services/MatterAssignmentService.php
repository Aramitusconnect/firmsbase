<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use RuntimeException;

/**
 * MatterAssignmentService — Mission 5A (Firm Daily-Workflow Completion).
 * Before this service, MatterAssignment rows were only ever created
 * inside MatterCreationService::create()'s own optional
 * $assignedStaffUserIds loop, at matter-creation time — there was no
 * way to staff/unstaff a matter afterward. This service is the
 * post-creation management path; it does not touch
 * MatterCreationService's own creation-time loop.
 *
 * assertUserIsActiveFirmMember() mirrors
 * MatterCreationService::assertUserIsActiveFirmMember() exactly (same
 * firm_users FORCE-RLS-protected check, same runWithFirmContext()
 * wrap) — kept as a private duplicate here rather than extracting a
 * shared helper, since MatterCreationService is explicitly out of
 * scope for this mission (Mission 2 territory) and must not be edited.
 *
 * remove() sets removed_at rather than deleting the row — the same
 * "removed_at not deletion" convention MatterAssignment's own docblock
 * documents, preserving staffing history (MatterAccessPolicyService's
 * own per-record boundary for non-blanket-access roles reads
 * removed_at IS NULL, so a removed assignment stops granting access
 * immediately without losing the historical record).
 */
class MatterAssignmentService
{
    /**
     * @throws RuntimeException if the user is not an active member of the matter's firm,
     *                          or already holds an active assignment on this matter
     */
    public function add(Matter $matter, User $user, string $role, bool $isLead, FirmUser $actor): MatterAssignment
    {
        $this->assertUserIsActiveFirmMember($matter, $user, 'assignee');
        $this->assertActorBelongsToFirm($matter, $actor);

        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $user, $role, $isLead) {
            $existing = MatterAssignment::query()
                ->where('matter_id', $matter->id)
                ->where('user_id', $user->id)
                ->whereNull('removed_at')
                ->first();

            if ($existing !== null) {
                throw new RuntimeException('This user already has an active assignment on this matter.');
            }

            return MatterAssignment::create([
                'matter_id' => $matter->id,
                'user_id' => $user->id,
                'role' => $role !== '' ? $role : null,
                'is_lead' => $isLead,
                'assigned_at' => now(),
            ]);
        });
    }

    /**
     * @throws RuntimeException if the assignment does not belong to the given matter, or is already removed
     */
    public function remove(Matter $matter, MatterAssignment $assignment, FirmUser $actor): MatterAssignment
    {
        $this->assertActorBelongsToFirm($matter, $actor);

        if ((int) $assignment->matter_id !== (int) $matter->id) {
            throw new RuntimeException('This assignment does not belong to the given matter.');
        }

        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $assignment) {
            $fresh = MatterAssignment::query()
                ->where('id', $assignment->id)
                ->where('matter_id', $matter->id)
                ->firstOrFail();

            if ($fresh->removed_at !== null) {
                throw new RuntimeException('This assignment has already been removed.');
            }

            $fresh->update(['removed_at' => now()]);

            return $fresh->fresh();
        });
    }

    private function assertActorBelongsToFirm(Matter $matter, FirmUser $actor): void
    {
        if ((int) $actor->firm_id !== (int) $matter->firm_id) {
            throw new RuntimeException('Refusing to modify matter staffing: the acting user does not belong to this matter\'s firm.');
        }
    }

    private function assertUserIsActiveFirmMember(Matter $matter, User $user, string $label): void
    {
        $isActiveMember = (new TenantContextService)->runWithFirmContext(
            $matter->firm_id,
            fn (): bool => FirmUser::query()
                ->where('firm_id', $matter->firm_id)
                ->where('user_id', $user->id)
                ->where('status', FirmUserStatus::Active)
                ->exists(),
        );

        if (! $isActiveMember) {
            throw new RuntimeException(
                "Refusing to assign this matter: {$label} (user {$user->id}) is not an active member of firm {$matter->firm_id}."
            );
        }
    }
}
