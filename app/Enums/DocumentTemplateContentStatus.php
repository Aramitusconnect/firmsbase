<?php

namespace App\Enums;

/**
 * DocumentTemplateContentStatus — document_template_versions.
 * content_status. Approved only via DocumentTemplateService::
 * approveContent(), which requires a typed actor: PlatformAdmin if the
 * owning DocumentTemplate is global (firm_id null), or a FirmUser with
 * role FirmOwner/Attorney of that SAME firm if firm-specific. No AI
 * actor type exists anywhere in the system, so no AI can ever satisfy
 * this. GeneratedDocumentService/DocumentReviewService re-check this
 * value live at approval time — a generated document created while
 * this was SampleOnly cannot become Approved until this flips to
 * ReviewedApproved.
 */
enum DocumentTemplateContentStatus: string
{
    case SampleOnly = 'sample_only';
    case ReviewedApproved = 'reviewed_approved';
}
