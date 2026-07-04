<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\DocumentRequest;
use App\Models\Invoice;
use App\Models\Matter;

/**
 * MobilePortalReadinessService — backend readiness flags/services only
 * (approved decision). No frontend assets, no PWA manifest/service
 * worker file, no routes/views/controllers/Filament/Livewire. Payment
 * link readiness does NOT implement Stripe (that's Phase 6); signature
 * flow readiness does NOT implement e-signatures (that's Phase 11) —
 * both return schema/service-level readiness booleans only.
 */
class MobilePortalReadinessService
{
    /**
     * Camera upload is a client-side browser capability
     * (input[capture]/getUserMedia) with no server-side dependency
     * beyond Phase 4's existing DocumentSecurityService::upload() path
     * (any image mime type it already accepts, e.g. image/jpeg, works
     * the same whether captured by camera or picked from a file
     * browser) — so readiness is a static true, not a per-firm/per-
     * matter computed check.
     */
    public function cameraUploadSupported(): bool
    {
        return true;
    }

    /**
     * "Save-and-continue intake" needs the intake submission to be
     * persistable in a Draft state and resumable later — Phase 2's
     * IntakeSubmissionStatus already has a Draft case and
     * IntakeSubmission rows are addressable by firm+client+matter, so
     * no new schema is required.
     */
    public function saveAndContinueIntakeSupported(): bool
    {
        return true;
    }

    /**
     * True when this matter has at least one document request whose
     * items can be rendered as a checklist (Phase 4's
     * DocumentRequest::items() already provides label/status/
     * is_required per item — exactly what a checklist UI needs).
     */
    public function documentChecklistAvailable(Matter $matter): bool
    {
        return DocumentRequest::query()->where('matter_id', $matter->id)->exists();
    }

    /**
     * Schema/service readiness only — no real Stripe payment link is
     * generated (project rule; Stripe belongs to Phase 6). True when
     * the invoice is in a sendable/collectible state and has an
     * outstanding balance; the actual link generation is explicitly
     * Phase 6 scope.
     */
    public function paymentLinkReadiness(Invoice $invoice): bool
    {
        $collectibleStatuses = [
            InvoiceStatus::Sent,
            InvoiceStatus::PartiallyPaid,
        ];

        return in_array($invoice->status, $collectibleStatuses, true)
            && $invoice->total_cents > $invoice->amount_paid_cents;
    }

    /**
     * Schema readiness only — no e-signature engine exists (project
     * rule; e-signatures are Phase 11). True simply confirms the
     * concept is representable with what already exists: a document
     * can reach DocumentStatus::PendingReview/Approved and carry a
     * file_hash, which is the minimum evidence trail a future
     * signature-request record would need to reference. No signature
     * capture, no signature_requests table, no consent-to-sign flow is
     * built here.
     */
    public function signatureFlowReadiness(): bool
    {
        return true;
    }

    /**
     * PWA install support depends only on serving a valid web app
     * manifest + service worker from the frontend build — no backend
     * schema/service dependency exists, so this simply documents that
     * the backend imposes no blocker. No manifest/service-worker file
     * is created in this phase (project rule).
     */
    public function pwaInstallSupported(): bool
    {
        return true;
    }
}
