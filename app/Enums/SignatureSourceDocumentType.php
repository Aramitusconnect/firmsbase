<?php

namespace App\Enums;

/**
 * SignatureSourceDocumentType — shared source-document typing used by
 * signature_requests, document_hashes, and pdf_view_events. Exactly one
 * of document_id / generated_document_id is set on the owning row,
 * matching this type — enforced at the service layer (explicit XOR
 * check), not a morph relation. Mirrors the dual-FK pattern already
 * established in Phase 10 (form_drafts source typing).
 */
enum SignatureSourceDocumentType: string
{
    case Document = 'document';
    case GeneratedDocument = 'generated_document';
}
