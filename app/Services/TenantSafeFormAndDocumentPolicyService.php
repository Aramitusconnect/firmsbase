<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\FormDraft;
use App\Models\GeneratedDocument;

/**
 * TenantSafeFormAndDocumentPolicyService — explicit, defense-in-depth
 * cross-firm guard, mirroring Phase 8/9's TenantSafe*PolicyService
 * pattern. Independent of whatever TenantContext (if any) is currently
 * active via BelongsToTenant's global scope.
 */
class TenantSafeFormAndDocumentPolicyService
{
    public function assertFormDraftBelongsToFirm(FormDraft $draft, Firm $firm): void
    {
        if ($draft->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('FormDraft [id=%s] does not belong to firm [id=%s].', $draft->id, $firm->id)
            );
        }
    }

    public function assertGeneratedDocumentBelongsToFirm(GeneratedDocument $document, Firm $firm): void
    {
        if ($document->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                sprintf('GeneratedDocument [id=%s] does not belong to firm [id=%s].', $document->id, $firm->id)
            );
        }
    }
}
