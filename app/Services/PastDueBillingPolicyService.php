<?php

namespace App\Services;

use App\Models\Firm;

/**
 * PastDueBillingPolicyService — the Phase 6 billing-enforcement layer
 * that Phase 5's LegalDataAccessPolicyService docblock explicitly
 * named as its intended future caller. Does NOT reimplement any
 * read/write/export decision — it delegates entirely to the EXISTING
 * LegalDataAccessPolicyService (Phase 5), which already governs access
 * by the firm's EXISTING LicenseStatus. This service exists only to
 * give Phase 6's billing/downgrade callers a billing-domain-named entry
 * point, and to translate the result into a small summary shape.
 */
class PastDueBillingPolicyService
{
    public function __construct(private LegalDataAccessPolicyService $legalDataAccessPolicy)
    {
    }

    public function canRead(Firm $firm): bool
    {
        return $this->legalDataAccessPolicy->canRead($firm);
    }

    public function canWrite(Firm $firm): bool
    {
        return $this->legalDataAccessPolicy->canWrite($firm);
    }

    public function canExport(Firm $firm): bool
    {
        return $this->legalDataAccessPolicy->canExport($firm);
    }

    /**
     * @return array{status: ?string, can_read: bool, can_write: bool, can_export: bool}
     */
    public function summary(Firm $firm): array
    {
        return [
            'status' => $this->legalDataAccessPolicy->currentStatus($firm)?->value,
            'can_read' => $this->canRead($firm),
            'can_write' => $this->canWrite($firm),
            'can_export' => $this->canExport($firm),
        ];
    }
}
