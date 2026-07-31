<?php

namespace App\Enums;

/**
 * FirmProvisioningStatus — firm_provisioning_requests.status.
 *
 *   - Pending: the idempotency row has been claimed (INSERT won the
 *     unique-constraint race) but the local provisioning transaction
 *     has not yet committed. A crash between claim and commit leaves a
 *     row here permanently — safe, since it never became `completed`
 *     and nothing else keys off it.
 *   - Completed: the local transaction committed AND the invitation was
 *     dispatched successfully.
 *   - InvitationFailed: the local transaction committed (the Firm/User/
 *     FirmUser/license/settings/entitlements/encryption key all exist),
 *     but sending the owner's invitation failed. The firm stays in
 *     Onboarding; ResendFirmOwnerInvitationAction is the recovery path.
 *   - Failed: the local transaction itself rolled back — no Firm/User/
 *     FirmUser record was left behind.
 */
enum FirmProvisioningStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case InvitationFailed = 'invitation_failed';
    case Failed = 'failed';
}
