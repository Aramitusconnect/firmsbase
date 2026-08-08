<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommunicationConsent;
use App\Models\User;
use App\Services\ConsentAccessPolicyService;

/**
 * CommunicationConsentPolicy — mirrors PaymentPolicy's shape exactly:
 * viewAny()/view() ONLY. `App\Services\ConsentService` is the sole
 * writer of `communication_consents` (capture()/revoke()) — there is no
 * CreateRecord/EditRecord page anywhere in this module (see
 * CommunicationConsentResource's own docblock), so a `create()`/
 * `update()` policy method here would imply an authorization surface
 * that does not exist. "Record Consent"/"Revoke" are each gated
 * directly against `ConsentAccessPolicyService::canCapture()`/
 * `canRevoke()` inside their own Action closures (CaptureConsentAction/
 * CaptureClientConsentAction/RevokeConsentAction), matching
 * RecordPaymentAction's established convention for a module whose real
 * writes only ever happen through named domain-service methods.
 */
class CommunicationConsentPolicy
{
    public function __construct(
        private readonly ConsentAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, CommunicationConsent $communicationConsent): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $communicationConsent->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }
}
