<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Services\AiModeResolutionService;

/**
 * AiPolicyDefinitionRegistry — the typed definitions for
 * `ai_policy_settings` keys, derived ENTIRELY from what services in
 * this codebase actually read.
 *
 * WHY THIS IS SO SMALL, AND WHY IT IS NOT INVENTED
 * ------------------------------------------------
 * `ai_policy_settings` is a generic (key, value_json) table with no
 * schema, no type column, and no definition registry — mission section
 * 52's "generic key/value domain" stop gate applies exactly. That
 * section is explicit that types, enums, ranges, defaults and
 * inheritance must NOT be inferred from current values or invented in
 * the UI.
 *
 * So this registry was built the only safe way: by inventorying every
 * consumer. A repository-wide search for reads of this table finds
 * exactly ONE consumed key across the whole codebase —
 * AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY
 * ('platform_ai_enabled'), read by platformKillSwitchEngaged(). Its
 * type, semantics and default are not guessed: they are read directly
 * off that consumer, which treats an explicit `false` as "kill switch
 * engaged" and BOTH an absent row and any other value as "not
 * engaged".
 *
 * Everything else in the table is therefore genuinely undefined, and is
 * reported as such rather than given a fabricated type.
 *
 * THE SECURITY PROBLEM THIS CLOSES
 * --------------------------------
 * 'platform_ai_enabled' is the platform-wide AI kill switch. It has a
 * purpose-built governed control — ToggleAiKillSwitchAction — which
 * requires step-up re-authentication and a written reason. But
 * AiPolicySettingResource's generic "Edit Value" action could edit ANY
 * row as free-form JSON, including this one, which meant:
 *
 *   1. A second, ungoverned path to the kill switch existed, bypassing
 *      both the step-up auth and the mandatory reason.
 *   2. Because platformKillSwitchEngaged() tests `=== false`, writing
 *      any non-false JSON (the string "false", 0, null, "banana")
 *      SILENTLY DISENGAGES the kill switch — re-enabling AI
 *      platform-wide — while looking to the operator like an edit.
 *
 * This registry marks such keys GOVERNED. AiPolicySettingService
 * refuses a governed write that does not come through the canonical
 * action, and type-validates the value, so neither failure mode is
 * reachable. This does not create a second kill switch (mission
 * section 55) — it removes a second, unsafe way to operate the
 * existing one.
 */
class AiPolicyDefinitionRegistry
{
    public const TYPE_BOOLEAN = 'boolean';

    public const CATEGORY_AVAILABILITY = 'Availability';

    /**
     * Every key with a real, proven consumer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY => [
                'key' => AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY,
                'label' => 'Platform AI enabled',
                'category' => self::CATEGORY_AVAILABILITY,
                'type' => self::TYPE_BOOLEAN,
                'consumer' => AiModeResolutionService::class.'::platformKillSwitchEngaged()',
                'description' => 'Master switch checked before every AI call in the system, ahead of any individual firm\'s mode, entitlement or provider keys.',
                // Read straight off the consumer: only an explicit false
                // engages the switch; an absent row means enabled.
                'absent_meaning' => 'Enabled (no row has ever been written)',
                'engaged_when' => false,
                'governed' => true,
                'governed_by' => 'AI Oversight → Toggle AI kill switch',
                'governed_reason' => 'The platform-wide AI kill switch is operated from AI Oversight, which requires step-up re-authentication and a written reason. Editing it as raw JSON here would bypass both, and any non-boolean value would silently disengage it.',
                'security_sensitivity' => 'High — platform-wide AI availability',
                'secret' => false,
                'firm_override_capability' => 'None — ai_policy_settings has no firm_id column; this table is platform-level only.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * A GOVERNED key has a dedicated, safer control elsewhere and must
     * not be written through the generic raw-JSON editor.
     */
    public function isGoverned(string $key): bool
    {
        return (bool) ($this->find($key)['governed'] ?? false);
    }

    /**
     * Is this key consumed by any service at all? A key with no
     * definition is not "invalid" — this table is deliberately generic
     * — but it IS unrecognized, and the console says so rather than
     * implying it does something (mission section 100).
     */
    public function isRecognized(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Validates a proposed value against the key's REAL type. Returns
     * an error message, or null when acceptable. Unrecognized keys are
     * unconstrained — there is no real type to enforce, and inventing
     * one would be exactly the fabrication section 52 forbids.
     */
    public function validate(string $key, mixed $value): ?string
    {
        $definition = $this->find($key);

        if ($definition === null) {
            return null;
        }

        if ($definition['type'] === self::TYPE_BOOLEAN && ! is_bool($value)) {
            return sprintf(
                '"%s" must be a boolean (true or false). A non-boolean value would be silently ignored by %s, which compares strictly.',
                $key,
                $definition['consumer'],
            );
        }

        return null;
    }

    /**
     * Operator-facing description of a stored value's meaning, for keys
     * whose semantics are known.
     */
    public function describeValue(string $key, mixed $value): string
    {
        $definition = $this->find($key);

        if ($definition === null) {
            return 'Not consumed by any service in this codebase — stored value only.';
        }

        if ($key === AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY) {
            return $value === false
                ? 'ENGAGED — AI is disabled platform-wide for every firm.'
                : 'Not engaged — AI availability falls through to each firm\'s own mode and entitlement.';
        }

        return 'Configured.';
    }
}
