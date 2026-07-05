<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Firm;

/**
 * TenantSafeEmailPolicyService — explicit, defense-in-depth cross-firm
 * guard, mirroring Phase 8's TenantSafeImportExportPolicyService.
 * Independent of whatever TenantContext (if any) BelongsToTenant's
 * global scope is currently applying.
 */
class TenantSafeEmailPolicyService
{
    public function assertEmailAccountBelongsToFirm(EmailAccount $account, Firm $firm): void
    {
        if ($account->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('EmailAccount [id=%s] does not belong to firm [id=%s].', $account->id, $firm->id)
            );
        }
    }

    public function assertEmailMessageBelongsToFirm(EmailMessage $message, Firm $firm): void
    {
        if ($message->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('EmailMessage [id=%s] does not belong to firm [id=%s].', $message->id, $firm->id)
            );
        }
    }
}
