<?php

namespace App\Services;

use App\Enums\ApiKeyScopeCode;
use App\Enums\ApiKeyStatus;
use App\Enums\FirmUserRole;
use App\Models\ApiKey;
use App\Models\FirmUser;
use App\ValueObjects\ApiAccessDecision;

/**
 * ApiAccessPolicyService — the single gate every firm API access must
 * pass through:
 *   1. key must be Active and not expired.
 *   2. key must carry the requested scope.
 *   3. for firm-type keys, EntitlementService::isEnabled($firmId, 'api')
 *      must be true (approved correction #6 — no new module_catalog
 *      row is added; the existing Phase 6 'api' code is reused as-is).
 *   4. rate limit: recent api_requests rows for this key, counted over
 *      the last minute, must not exceed rate_limit_per_minute (approved
 *      correction #8 — calculation only, no middleware/throttling).
 *   5. canManageApiKeys(): a FirmUserRole allowlist (approved correction
 *      #7 — no generic ACL/permission engine). Only FirmOwner and
 *      Attorney may create/rotate/revoke a firm's own API keys.
 */
class ApiAccessPolicyService
{
    private const KEY_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly ApiKeyScopeService $apiKeyScopeService,
        private readonly ApiRequestAuditService $apiRequestAuditService,
    ) {
    }

    public function canUseScope(ApiKey $key, ApiKeyScopeCode $scope): ApiAccessDecision
    {
        if ($key->status !== ApiKeyStatus::Active) {
            return ApiAccessDecision::deny('api key is not active');
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return ApiAccessDecision::deny('api key has expired');
        }

        if (! $this->apiKeyScopeService->hasScope($key, $scope)) {
            return ApiAccessDecision::deny('api key does not carry the requested scope');
        }

        if ($key->isFirmKey() && ! $this->entitlementService->isEnabled($key->firm_id, 'api')) {
            return ApiAccessDecision::deny('firm is not entitled to the api module');
        }

        if (! $this->isWithinRateLimit($key)) {
            return ApiAccessDecision::deny('rate limit exceeded');
        }

        return ApiAccessDecision::allow();
    }

    public function isWithinRateLimit(ApiKey $key): bool
    {
        if ($key->rate_limit_per_minute === null) {
            return true;
        }

        $recentCount = $this->apiRequestAuditService->recentCountForKey($key, now()->subMinute());

        return $recentCount < $key->rate_limit_per_minute;
    }

    public function canManageApiKeys(FirmUser $actor): ApiAccessDecision
    {
        if (in_array($actor->role, self::KEY_MANAGEMENT_ROLES, true)) {
            return ApiAccessDecision::allow();
        }

        return ApiAccessDecision::deny('role is not permitted to manage firm API keys');
    }
}
