<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * MobilePortalCoverageMappingService — declares the master plan's
 * Section 30 mobile client portal / document scanner capabilities (11
 * keys) and maps each to an EXISTING owning mechanism or a known,
 * honestly-classified gap. Purely declarative — no scanner, OCR, PDF
 * conversion, or SMS/WhatsApp sending is implemented here or anywhere
 * else in this section. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25-29 cross-cutting
 * package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written.
 */
class MobilePortalCoverageMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'camera_upload',
                item_label: 'Camera-based document upload from a mobile client',
                owning_class: \App\Services\MobilePortalReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'MobilePortalReadinessService::cameraUploadSupported() returns a real readiness flag, and DocumentSecurityService::upload() plus DocumentUploadPolicyService genuinely accept jpg/jpeg/png/tif/tiff image uploads (the exact formats a camera capture would produce), enforcing extension/size rules before a Document row is ever created.',
            ),
            new GovernanceMappingResult(
                item_key: 'auto_crop',
                item_label: 'Automatic edge detection / auto-crop of a captured document photo',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No image-processing library or auto-crop routine of any kind exists anywhere in the repository or composer.json (confirmed by direct search). This is an intentionally unbuilt future capability, not a regression or gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'pdf_conversion',
                item_label: 'Client-side or server-side conversion of captured images into a single PDF',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'DocumentUploadPolicyService allows pdf as an upload extension, but nothing in the repository converts an uploaded image into a PDF — no document-conversion library or service exists anywhere (confirmed by direct search). Intentionally unbuilt.',
            ),
            new GovernanceMappingResult(
                item_key: 'file_quality_warnings',
                item_label: 'Warnings for blurry/low-quality/unreadable captured images',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'DocumentUploadPolicyService only validates file extension and byte size; it has no concept of image sharpness, resolution, or readability, and no such concept exists anywhere else in the repository. Intentionally unbuilt.',
            ),
            new GovernanceMappingResult(
                item_key: 'missing_side_detection_ids',
                item_label: 'Detection of a missing front/back side for a two-sided ID document',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'DocumentRequestItem carries only label/status/is_required/viewed_at/submitted_at/reviewed_by/reviewed_at/rejected_reason/waived_by/waived_at/expires_at — there is no front/back or multi-side concept on this model or on Document, and no service anywhere infers or checks for a missing side. Intentionally unbuilt.',
            ),
            new GovernanceMappingResult(
                item_key: 'checklist_progress',
                item_label: 'Client-visible document checklist progress',
                owning_class: \App\Services\MobilePortalReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'MobilePortalReadinessService::documentChecklistAvailable() queries real DocumentRequest/DocumentRequestItem rows for a matter, and DocumentRequestItem.status genuinely tracks per-item progress (viewed/submitted/reviewed/rejected/waived) — real, queryable checklist state exists today.',
            ),
            new GovernanceMappingResult(
                item_key: 'sms_whatsapp_ready_reminder_links',
                item_label: 'SMS/WhatsApp-ready reminder links for outstanding checklist items or payments',
                owning_class: \App\Services\PaymentPlanDunningService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'ConsentChannel::Sms and ConsentChannel::WhatsApp are real enum cases, and both PaymentPlanDunningService::checkAndLog() and DocumentChaseRule.channel genuinely evaluate consent eligibility per channel via the existing ConsentService — a real, tested eligibility layer exists. However, no real link generation or message-sending exists for any channel; PaymentPlanDunningService\'s own docblock states it only checks eligibility and logs the attempt, never sends anything. Cannot be Implemented while no real link/send mechanism exists.',
            ),
            new GovernanceMappingResult(
                item_key: 'save_and_continue_intake',
                item_label: 'Save-and-continue support for a client intake form',
                owning_class: \App\Services\MobilePortalReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'MobilePortalReadinessService::saveAndContinueIntakeSupported() returns a real readiness flag, and IntakeSubmissionStatus::Draft genuinely represents a resumable, not-yet-submitted intake row.',
            ),
            new GovernanceMappingResult(
                item_key: 'mobile_payment_plan_visibility',
                item_label: 'Client-visible payment plan / installment schedule from a mobile client',
                owning_class: \App\Models\PaymentPlan::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlan and PaymentPlanInstallment are real, firm/client/matter-scoped, queryable models carrying status, sequence, amount_cents, due_at, paid_amount_cents — schema-ready for a mobile client to display, even though no mobile UI itself is built in this section.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_links',
                item_label: 'Payment links a client can pay from a mobile device',
                owning_class: \App\Services\MobilePortalReadinessService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'MobilePortalReadinessService::paymentLinkReadiness() is a real, tested readiness check based on genuine Invoice status/amount fields (Sent/PartiallyPaid with an outstanding balance). Its own docblock is explicit that it does not implement a real Stripe payment link — no payment-provider integration exists anywhere in this repository. Cannot be Implemented while no real link-generation/provider mechanism exists.',
            ),
            new GovernanceMappingResult(
                item_key: 'client_facing_receipts',
                item_label: 'Client-facing payment receipts',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'The only Receipt-named model in the repository is ExpenseReceipt, which represents an internal firm expense record, not a client-facing payment confirmation. No client-facing receipt concept, template, or generation mechanism exists anywhere (confirmed by direct search) — Payment rows exist but nothing renders or issues a receipt from them to a client.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function notApplicableYet(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotApplicableYet);
    }

    /**
     * @return array<int, GovernanceMappingResult> every item not classified Implemented
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status !== GovernanceMappingStatus::Implemented,
        ));
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        ));
    }
}
