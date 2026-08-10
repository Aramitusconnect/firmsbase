<?php

namespace App\Services\Automation;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * AutomationRecipientResolverService — Event-Driven Automation Engine,
 * item 6. Shared by every task-creating action handler. No dedicated
 * "recipients for role X" service existed anywhere in this codebase
 * before this pass (confirmed by audit) — every prior BillingStaff/
 * Attorney lookup was either a single-person role-gate check or a
 * one-off inline query; this centralizes the query shape those inline
 * call sites already used (FirmUser::where('role', ...)->where('status',
 * Active)->get()) rather than inventing a new one.
 */
class AutomationRecipientResolverService
{
    /**
     * @return Collection<int, User>
     */
    public function usersWithRole(Firm $firm, FirmUserRole $role): Collection
    {
        return FirmUser::query()
            ->with('user')
            ->where('firm_id', $firm->id)
            ->where('role', $role)
            ->where('status', FirmUserStatus::Active)
            ->get()
            ->map(fn (FirmUser $firmUser) => $firmUser->user)
            ->filter()
            ->values();
    }

    public function matterAssignedAttorney(Firm $firm, ?int $matterId): ?User
    {
        if ($matterId === null) {
            return null;
        }

        $matter = Matter::query()->where('firm_id', $firm->id)->find($matterId);

        return $matter?->assignedAttorney;
    }
}
