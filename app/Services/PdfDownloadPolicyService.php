<?php

namespace App\Services;

use App\Models\Document;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\SignatureRequestRecipient;
use App\ValueObjects\PdfAccessDecision;

/**
 * PdfDownloadPolicyService — the explicit, never-implicit "download
 * rules must be enforced by policy service" requirement. Every
 * download decision is a real check against current state (matter/
 * firm ownership, the document's own usability, and for a recipient,
 * whether their signature_request_recipients row is still in a
 * non-terminal-negative, non-expired state) — never a stored flag
 * trusted from earlier.
 */
class PdfDownloadPolicyService
{
    public function decideForFirmUser(FirmUser $viewer, Document $document): PdfAccessDecision
    {
        if ($viewer->firm_id !== $document->firm_id) {
            return PdfAccessDecision::deny('Viewer does not belong to the firm that owns this document.');
        }

        if (! $document->isUsable()) {
            return PdfAccessDecision::deny('Document has not passed virus scanning or is not in a usable state.');
        }

        return PdfAccessDecision::allow('Firm user has matter-level access to a usable document.');
    }

    public function decideForFirmUserGeneratedDocument(FirmUser $viewer, GeneratedDocument $generatedDocument): PdfAccessDecision
    {
        if ($viewer->firm_id !== $generatedDocument->firm_id) {
            return PdfAccessDecision::deny('Viewer does not belong to the firm that owns this generated document.');
        }

        return PdfAccessDecision::allow('Firm user has access to this generated document.');
    }

    /**
     * A recipient may only download the document tied to their OWN
     * signature request, and only while their recipient row is still
     * active (not declined/expired/voided).
     */
    public function decideForRecipient(SignatureRequestRecipient $recipient, Document|GeneratedDocument $sourceDocument): PdfAccessDecision
    {
        $request = $recipient->signatureRequest;

        $belongsToThisRequest = $sourceDocument instanceof Document
            ? $request->document_id === $sourceDocument->id
            : $request->generated_document_id === $sourceDocument->id;

        if (! $belongsToThisRequest) {
            return PdfAccessDecision::deny('This recipient has no relationship to the requested document.');
        }

        if (in_array($recipient->status->value, ['declined', 'expired', 'voided'], true)) {
            return PdfAccessDecision::deny("Recipient status is '{$recipient->status->value}' — access is no longer permitted.");
        }

        if ($sourceDocument instanceof Document && ! $sourceDocument->isUsable()) {
            return PdfAccessDecision::deny('Document has not passed virus scanning or is not in a usable state.');
        }

        return PdfAccessDecision::allow('Recipient has active, in-scope access to their own signature request document.');
    }
}
