<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;

/**
 * AiPolicySettingService — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Configuration" category) addition. The FIRST service layer
 * `ai_policy_settings` has ever had (per this phase's own architecture
 * investigation: "Zero service layer, zero UI, zero test file exists
 * for AiPolicySetting" — only the model, its factory, and the creating
 * migration existed before this class).
 *
 * Backs AiPolicySettingResource, the honest, narrowly-scoped relabeling
 * of "Platform Settings" — this module manages ONLY platform-wide AI
 * policy defaults (the real, Global/no-RLS `ai_policy_settings` table),
 * never general platform configuration (site name, defaults, etc.),
 * which has no backing store anywhere in this codebase and is not
 * built here (per the architecture investigation's Open Decision 4).
 *
 * `key` is unique (DB-level constraint); set() is a plain upsert keyed
 * on it. No caching layer, mirroring EntitlementService's own explicit
 * "no caching layer — a plain query per resolution" precedent — this
 * table is expected to be read rarely (platform-wide guardrail checks,
 * not a per-request hot path) and correctness/simplicity is preferred
 * over premature optimization.
 *
 * `updated_by` (the model's real FK column) points at `users`, not
 * `platform_admins` — a genuine, pre-existing data-model inconsistency
 * this phase does not fix (no schema change authorized here). Mirrors
 * the same actor-type-gap resolution pattern used throughout this
 * phase (e.g. EntitlementOverrideService::setOverrideAsPlatformAdmin()):
 * `updated_by` is left null for every admin-initiated write through
 * this service — an honest signal, never a fabricated/misattributed
 * User id — and real PlatformAdmin attribution instead lives in the
 * `security_events` audit row this class writes via
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() (the
 * null-firm_id variant — correct here, unlike
 * SupportAccessRequestService::expire()/
 * EntitlementOverrideService::setOverrideAsPlatformAdmin()'s use of the
 * firm-scoped record(), because AiPolicySetting genuinely has no
 * firm_id at all: it is Global/no-RLS platform-wide configuration, the
 * same class of entity PlatformInvoiceService's own actor/audit
 * addition already established this pattern for).
 */
class AiPolicySettingService
{
    private const AUDIT_CATEGORY = 'ai_policy_settings';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    /**
     * Returns the decoded value_json for $key, or null if no row
     * exists yet.
     */
    public function get(string $key): mixed
    {
        return AiPolicySetting::query()->where('key', $key)->first()?->value_json;
    }

    public function find(string $key): ?AiPolicySetting
    {
        return AiPolicySetting::query()->where('key', $key)->first();
    }

    /**
     * Create-or-update ("upsert") the row for $key. When $actor is
     * supplied, records a `security_events` platform-level audit row
     * (see this class's own docblock for why updated_by itself stays
     * null rather than receiving the PlatformAdmin's id). $reason is
     * optional (most callers, e.g. EditAiPolicySettingValueAction,
     * have none) and — when supplied — is folded into that same audit
     * row's metadata rather than opening a second write path; added
     * for ToggleAiKillSwitchAction (MYAT8), which requires one.
     */
    public function set(string $key, mixed $value, ?PlatformAdmin $actor = null, ?string $reason = null): AiPolicySetting
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('An AI policy setting key is required.');
        }

        $existing = AiPolicySetting::query()->where('key', $key)->first();

        $attributes = [
            'key' => $key,
            'value_json' => $value,
        ];

        $setting = $existing
            ? tap($existing)->update($attributes)
            : AiPolicySetting::create($attributes);

        $fresh = $setting->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                $existing ? 'ai_policy_setting_updated' : 'ai_policy_setting_created',
                self::AUDIT_CATEGORY,
                array_filter([
                    'ai_policy_setting_id' => $fresh->id,
                    'key' => $fresh->key,
                    'reason' => $reason,
                ], fn ($value) => $value !== null),
            );
        }

        return $fresh;
    }
}
