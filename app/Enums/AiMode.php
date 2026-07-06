<?php

namespace App\Enums;

/**
 * AiMode — firm_settings.ai_mode. Replaced in place for Phase 15
 * (approved decision #1): the Phase 1 stub values (Disabled/AssistOnly/
 * AssistWithApproval) described an approval posture, not a key-ownership
 * posture, and never matched the Master Plan's Phase 15 data contract.
 * No other file in the codebase referenced AssistOnly/AssistWithApproval
 * by name (confirmed by exhaustive grep before this change), so this
 * replacement is safe and does not require touching any other file's
 * logic beyond this enum itself.
 *
 * firm_settings.ai_mode remains the single source of truth for AI mode
 * (approved decision #1) — firm_ai_settings (new, Phase 15) holds only
 * the detailed controls (allowed providers/models, limits, toggles) and
 * never duplicates this value.
 *
 * - Disabled: no AI service may run for this firm. Every AI
 *   entry point (service, job, API surface) must block.
 * - PlatformManaged: AI runs through FirmsBase-provisioned access to a
 *   provider under a zero-retention agreement (Master Plan §22). No
 *   real provider integration exists in Phase 15 — this mode is only
 *   enforceable against the FakeAiProviderAdapter.
 * - FirmOwned: AI runs only using the firm's own encrypted provider
 *   key (firm_ai_provider_keys). Requires an Active key for the
 *   requested provider or the request must be blocked.
 */
enum AiMode: string
{
    case Disabled = 'disabled';
    case PlatformManaged = 'platform_managed';
    case FirmOwned = 'firm_owned';
}
