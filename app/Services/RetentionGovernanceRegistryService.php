<?php

namespace App\Services;

/**
 * RetentionGovernanceRegistryService — Checkpoint 9 (frozen-design-
 * post-security-review.md §8; agent-9d-retention-governance.md;
 * agent-9h-architecture-security-review.md §6). A pure, declarative,
 * READ-ONLY registry, structurally parallel to
 * App\Services\RowLevelSecurityCoverageMappingService: enumerates every
 * integration retention category with its governing table(s), exact
 * `config()` key, current resolved default, enforcing class/method, and
 * a status. Performs NO writes, dispatches no jobs, and reads the SAME
 * `config()` keys the sweep code itself reads — never a copy of the
 * numbers that could drift independently.
 *
 * REJECTED alternative (agent-9h §6.1, explicit ruling, not merely
 * deferred): seeding real `RetentionPolicy` rows or extending
 * `RetentionRecordType` for these categories. `App\Jobs\RetentionSweepJob`/
 * `App\Console\Commands\SweepIntegrationRetentionCommand` read every
 * integration retention window directly from `config()`, NEVER from
 * `RetentionPolicy` — seeding a real policy row here would let the
 * *displayed/queryable* policy diverge from the *actually enforced*
 * window the moment anyone called `RetentionPolicyService::supersede()`
 * against it, a genuine dual-source-of-truth defect, not a hypothetical
 * one. This registry is the correct, narrower alternative: read-only
 * visibility with no independent state of its own to drift.
 *
 * Status vocabulary (five closed values plus one non-exclusive flag):
 *   - CONFIGURED_DEFAULT: a real config() default exists and a live
 *     sweep method actually enforces it today.
 *   - CONFIGURED_PLACEHOLDER: a config() default exists but was chosen
 *     as an explicit, disclosed placeholder rather than a
 *     compliance-anchored number (none of this checkpoint's own
 *     categories currently carry this status — reserved for future use,
 *     matching agent-9d's full five-status design).
 *   - NOT_CONFIGURED_FAIL_SAFE: the config() key ships with NO default;
 *     any future sweep method must no-op with a disclosed log event on
 *     null, never guess a number.
 *   - OUT_OF_SCOPE_SNAPSHOT: a 1:1 upsert-cache table with no
 *     independent age of its own — a retention window is structurally
 *     meaningless for it.
 *   - NOT_YET_APPLICABLE: a config() key exists but no live sweep method
 *     enforces it yet.
 *   - LEGAL_HOLD_COVERAGE_UNRESOLVED (non-exclusive flag, may accompany
 *     any of the five statuses above): no resolution layer exists today
 *     mapping this category's `resource_type`/`local_id` back to a
 *     `client_id`/`matter_id` a `LegalHoldService::checkHold()` call
 *     could use — disclosed, named residual risk (agent-9h §6.4), NOT
 *     built by this checkpoint.
 */
class RetentionGovernanceRegistryService
{
    public const STATUS_CONFIGURED_DEFAULT = 'configured_default';

    public const STATUS_CONFIGURED_PLACEHOLDER = 'configured_placeholder';

    public const STATUS_NOT_CONFIGURED_FAIL_SAFE = 'not_configured_fail_safe';

    public const STATUS_OUT_OF_SCOPE_SNAPSHOT = 'out_of_scope_snapshot';

    public const STATUS_NOT_YET_APPLICABLE = 'not_yet_applicable';

    /**
     * Declarative category registry. `config_key` is null only for
     * OUT_OF_SCOPE_SNAPSHOT entries with no retention window of their
     * own. `current_default` is resolved live via config() at call
     * time in categories() below — never hardcoded here as a second
     * copy of the number.
     *
     * @var array<string, array{
     *     tables: array<int, string>,
     *     config_key: ?string,
     *     enforcing: string,
     *     status: string,
     *     legal_hold_coverage_unresolved: bool,
     *     notes: string,
     * }>
     */
    private const CATEGORIES = [
        'usage_records' => [
            'tables' => ['integration_usage_records'],
            'config_key' => 'integrations.usage_records.retention_days',
            'enforcing' => 'No live sweep method exists yet at Checkpoint 9 — columns/index only, matching every other Section-3-style table\'s "columns/index only, sweep wiring is separate scope" pattern.',
            'status' => self::STATUS_NOT_CONFIGURED_FAIL_SAFE,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'No prior anchor exists for this window (Checkpoint 9\'s own table). Ships with NO default (agent-9h-architecture-security-review.md §6.3), rejecting Agent 9A\'s own 400-day placeholder recommendation. Any future sweepUsageRecords() method must check for null and no-op with a disclosed log event, exactly mirroring the oauth_states.unconsumed_expired_retention_hours precedent, rather than inventing a number with no compliance anchor.',
        ],
        'connection_health' => [
            'tables' => ['integration_connection_health'],
            'config_key' => null,
            'enforcing' => 'None — structurally not applicable.',
            'status' => self::STATUS_OUT_OF_SCOPE_SNAPSHOT,
            'legal_hold_coverage_unresolved' => false,
            'notes' => '1:1 upsert cache (UNIQUE(firm_integration_id) plus cascadeOnDelete()) with no independent age of its own — governed entirely by its parent connection\'s lifecycle. A retention window is structurally meaningless for this table.',
        ],
        'sync_runs' => [
            'tables' => ['integration_sync_runs'],
            'config_key' => 'integrations.sync_runs.retention_days',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepSyncRuns()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => true,
            'notes' => 'RetentionSweepJob\'s automated, unattended sweep does not check LegalHoldService::checkHold() — that pattern exists exclusively inside the governed, human-approval-gated deletion-request workflow, a structurally different kind of operation. No resolution layer exists today mapping a sync run back to a client_id/matter_id.',
        ],
        'sync_items' => [
            'tables' => ['integration_sync_items'],
            'config_key' => 'integrations.sync_items.retention_days',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepSyncItems()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => true,
            'notes' => 'Same LEGAL_HOLD_COVERAGE_UNRESOLVED reasoning as sync_runs — a sync item can, in principle, describe activity against a legally-held invoice/document/matter, with no resource_type+local_id -> client_id/matter_id resolution layer today.',
        ],
        'outbox_events' => [
            'tables' => ['integration_outbox_events'],
            'config_key' => 'integrations.outbox.completed_retention_days / dead_lettered_retention_days / cancelled_retention_days',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepOutboxEvents()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'Three independent terminal-status windows (completed 30d, dead_lettered 90d, cancelled 30d), each from its own terminal timestamp.',
        ],
        'oauth_states_consumed' => [
            'tables' => ['integration_oauth_states'],
            'config_key' => 'integrations.oauth_states.consumed_retention_hours',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepOauthStates()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'The conservative/longer end of Checkpoint 5\'s frozen 24-72h range.',
        ],
        'oauth_states_unconsumed_expired' => [
            'tables' => ['integration_oauth_states'],
            'config_key' => 'integrations.oauth_states.unconsumed_expired_retention_hours',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepOauthStates()',
            'status' => self::STATUS_NOT_CONFIGURED_FAIL_SAFE,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'The original, proven fail-safe precedent this checkpoint\'s own usage_records window (above) is modeled on: ships with NO default; the sweep\'s own code path checks for null and no-ops with an explicit EVENT_OAUTH_STATE_UNCONSUMED_CLEANUP_NOT_CONFIGURED log line.',
        ],
        'conflicts_resolved' => [
            'tables' => ['integration_conflicts'],
            'config_key' => 'integrations.conflicts.retention_days',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepResolvedConflicts()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => true,
            'notes' => 'Resolved conflicts only (365 days from resolved_at) — an unresolved conflict is never swept by retention age alone. Same LEGAL_HOLD_COVERAGE_UNRESOLVED reasoning as sync_runs/sync_items.',
        ],
        'inbound_webhook_events' => [
            'tables' => ['integration_inbound_webhook_events'],
            'config_key' => 'integrations.webhook.event_redact_after_days (400, InboundWebhookEventService-computed retention_deadline) / integrations.webhook.event_delete_after_days (2555)',
            'enforcing' => 'App\\Jobs\\RetentionSweepJob::sweepProcessedWebhookEvents()',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'Two-stage redact-then-delete: Stage 1 redacts provider-originated content at the row\'s own retention_deadline (400 days, computed at insert time); Stage 2 deletes at 2555 days from received_at.',
        ],
        'webhook_receipts_verified' => [
            'tables' => ['integration_webhook_receipts'],
            'config_key' => 'integrations.webhook.receipt_verified_retention_days',
            'enforcing' => 'App\\Console\\Commands\\SweepIntegrationRetentionCommand',
            'status' => self::STATUS_CONFIGURED_DEFAULT,
            'legal_hold_coverage_unresolved' => false,
            'notes' => 'Platform-owned table (no firm_id column) — swept via the dedicated platform command, not the per-firm RetentionSweepJob.',
        ],
    ];

    /**
     * @return array<string, array{
     *     tables: array<int, string>,
     *     config_key: ?string,
     *     current_default: mixed,
     *     enforcing: string,
     *     status: string,
     *     legal_hold_coverage_unresolved: bool,
     *     notes: string,
     * }>
     */
    public function categories(): array
    {
        $result = [];

        foreach (self::CATEGORIES as $category => $entry) {
            $result[$category] = array_merge($entry, [
                'current_default' => $this->resolveCurrentDefault($entry['config_key']),
            ]);
        }

        return $result;
    }

    /**
     * @return array{
     *     tables: array<int, string>,
     *     config_key: ?string,
     *     current_default: mixed,
     *     enforcing: string,
     *     status: string,
     *     legal_hold_coverage_unresolved: bool,
     *     notes: string,
     * }|null
     */
    public function categoryFor(string $category): ?array
    {
        return $this->categories()[$category] ?? null;
    }

    public function statusFor(string $category): ?string
    {
        return $this->categoryFor($category)['status'] ?? null;
    }

    public function isLegalHoldCoverageUnresolved(string $category): bool
    {
        return (bool) ($this->categoryFor($category)['legal_hold_coverage_unresolved'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    public function categoriesWithUnresolvedLegalHoldCoverage(): array
    {
        return array_values(array_map(
            static fn (string $category) => $category,
            array_keys(array_filter(
                self::CATEGORIES,
                static fn (array $entry) => $entry['legal_hold_coverage_unresolved'],
            )),
        ));
    }

    /**
     * A single config_key entry may describe more than one underlying
     * config() key (e.g. outbox_events' three terminal-status windows)
     * — in that case no single scalar "current default" exists, and
     * this returns null rather than fabricating one; callers wanting
     * the individual values should read the documented config() keys
     * directly.
     */
    private function resolveCurrentDefault(?string $configKey): mixed
    {
        if ($configKey === null || str_contains($configKey, ' / ') || str_contains($configKey, ' (')) {
            return null;
        }

        return config($configKey);
    }
}
