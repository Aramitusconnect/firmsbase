<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentChaseRule;
use App\Models\User;
use App\Services\DocumentRequestAccessPolicyService;

/**
 * DocumentChaseRulePolicy — mirrors ContactPolicy's shape: `DocumentChaseRule`
 * has no creation restriction (confirmed by direct source read — the
 * only production callers are DocumentChaseSchedulerService::
 * applicableRule() (read-only) and DocumentChaseService (reads a rule
 * passed to it, never writes one) — no dedicated write service exists),
 * so unlike DocumentRequestPolicy this declares a real `create()`/
 * `update()` pair backing a genuine Filament CreateRecord/EditRecord
 * page (direct Eloquent write via WrapsRecordMutationInFirmContext is
 * therefore acceptable here, same reasoning ContactResource documents
 * for Contact).
 *
 * Ceiling is `canManageChaseRule()`, narrower than DocumentRequestPolicy's
 * `canManageRequest()` — see DocumentRequestAccessPolicyService's own
 * docblock for why firm-wide chase-rule configuration gets the
 * narrowest ceiling in this cluster.
 */
class DocumentChaseRulePolicy
{
    public function __construct(
        private readonly DocumentRequestAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, DocumentChaseRule $documentChaseRule): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $documentChaseRule->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageChaseRule($firmUser->role);
    }

    public function update(User $user, DocumentChaseRule $documentChaseRule): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $documentChaseRule->firm_id
            && $this->accessPolicy->canManageChaseRule($firmUser->role);
    }
}
