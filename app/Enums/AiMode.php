<?php

namespace App\Enums;

/**
 * AiMode — firm_settings.ai_mode. AI must never bypass human approval
 * for high-risk legal work or client-facing legal content (project rule
 * 14), and AI retrieval must never cross firm/matter permission
 * boundaries (project rule 15). This enum only records the firm's
 * configured mode; no AI behavior is implemented in Phase 1.
 */
enum AiMode: string
{
    case Disabled = 'disabled';
    case AssistOnly = 'assist_only';
    case AssistWithApproval = 'assist_with_approval';
}
