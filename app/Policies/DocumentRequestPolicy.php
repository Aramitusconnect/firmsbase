<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\DocumentRequestAccessPolicyService;

/**
 * DocumentRequestPolicy — mirrors DeadlinePolicy's shape: `create()`
 * governs whether "+ New Document Request" (which always calls
 * `DocumentRequestService::create()`, never a bare
 * `DocumentRequest::create()`) is permitted. `update()` governs
 * EditDocumentRequest's deliberately narrow safe-field-only form
 * (title/instructions/due_at) — `DocumentRequestService` has no
 * update() method for the parent request itself (confirmed by direct
 * source read: its 7 mutators all operate on a `DocumentRequestItem`,
 * never the parent), and `status`/`client_id`/`matter_id` are
 * deliberately NOT editable fields here: `status` is a derived
 * aggregate `DocumentRequestService::recomputeParentStatus()` owns
 * exclusively, and re-pointing `client_id`/`matter_id` after items
 * already exist would desynchronize the request from the party it was
 * actually addressed to. Every per-item status transition instead goes
 * through a dedicated Action (see DocumentRequestResource\Actions),
 * each calling `DocumentRequestService` directly.
 */
class DocumentRequestPolicy
{
    public function __construct(
        private readonly DocumentRequestAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, DocumentRequest $documentRequest): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $documentRequest->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageRequest($firmUser->role);
    }

    public function update(User $user, DocumentRequest $documentRequest): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $documentRequest->firm_id
            && $this->accessPolicy->canManageRequest($firmUser->role);
    }
}
