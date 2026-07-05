<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;

/**
 * TenantSafeSignatureAndPdfPolicyService — explicit, defense-in-depth
 * cross-firm guard, mirroring Phase 8/9/10's TenantSafe*PolicyService
 * pattern. Independent of whatever TenantContext (if any) is currently
 * active via BelongsToTenant's global scope.
 */
class TenantSafeSignatureAndPdfPolicyService
{
    public function assertSignatureRequestBelongsToFirm(SignatureRequest $request, Firm $firm): void
    {
        if ($request->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('SignatureRequest [id=%s] does not belong to firm [id=%s].', $request->id, $firm->id)
            );
        }
    }

    public function assertSignatureRequestRecipientBelongsToFirm(SignatureRequestRecipient $recipient, Firm $firm): void
    {
        if ($recipient->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('SignatureRequestRecipient [id=%s] does not belong to firm [id=%s].', $recipient->id, $firm->id)
            );
        }
    }

    public function assertSignatureCertificateBelongsToFirm(SignatureCertificate $certificate, Firm $firm): void
    {
        if ($certificate->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('SignatureCertificate [id=%s] does not belong to firm [id=%s].', $certificate->id, $firm->id)
            );
        }
    }
}
