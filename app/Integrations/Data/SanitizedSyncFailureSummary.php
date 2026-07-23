<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use InvalidArgumentException;

/**
 * SanitizedSyncFailureSummary — Checkpoint 9 (frozen design §3, agent-9b
 * §finding, agent-9h-architecture-security-review.md §2.3). The ONLY
 * shape `SyncRunService::transitionStatus()` should be given when it
 * needs to describe WHY a run failed, consumed by both
 * `firm_integrations.error_reason` and the new
 * `integration_sync.run_failed` audit event's `error_summary` metadata.
 *
 * Mirrors `App\Integrations\Data\SanitizedHealthDiagnostic`'s exact
 * constructor-validated-category shape: a closed `category` plus an
 * optional, bounded `itemsFailedCount` — never a free-text string built
 * ad hoc by a caller. Before this DTO existed, `transitionStatus()`'s
 * `?string $errorSummary` parameter was the one place an error string
 * reached a sole-writer method without first passing through a closed-
 * category boundary, unlike every other error-carrying value in this
 * codebase.
 *
 * Backward compatibility: `transitionStatus()` keeps its existing
 * `?string $errorSummary` parameter untouched (callers outside this
 * checkpoint's frozen file allowlist — `app/Jobs/PullSyncJob.php`,
 * `app/Jobs/PushSyncJob.php` — already pass a plain string and cannot
 * be modified here). This DTO is additive: a new, optional parameter
 * on `transitionStatus()` that a NEW caller (e.g. a future Checkpoint
 * 10 dispatch path) may pass instead of/alongside the raw string, never
 * a breaking signature change to the existing `?string` parameter.
 */
final class SanitizedSyncFailureSummary
{
    public const CATEGORY_CREDENTIAL_ERROR = 'credential_error';

    public const CATEGORY_SCOPE_ERROR = 'scope_error';

    public const CATEGORY_PROVIDER_ERROR = 'provider_error';

    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_VALIDATION_ERROR = 'validation_error';

    public const CATEGORY_ITEM_FAILURES = 'item_failures';

    public const CATEGORY_CANCELLED = 'cancelled';

    public const CATEGORY_INTERNAL_ERROR = 'internal_error';

    private const VALID_CATEGORIES = [
        self::CATEGORY_CREDENTIAL_ERROR,
        self::CATEGORY_SCOPE_ERROR,
        self::CATEGORY_PROVIDER_ERROR,
        self::CATEGORY_RATE_LIMITED,
        self::CATEGORY_VALIDATION_ERROR,
        self::CATEGORY_ITEM_FAILURES,
        self::CATEGORY_CANCELLED,
        self::CATEGORY_INTERNAL_ERROR,
    ];

    public function __construct(
        private readonly string $category,
        private readonly ?int $itemsFailedCount = null,
    ) {
        if (! in_array($category, self::VALID_CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown sync-failure-summary category: \"{$category}\".");
        }

        if ($itemsFailedCount !== null && $itemsFailedCount < 0) {
            throw new InvalidArgumentException('itemsFailedCount must be null or a non-negative integer.');
        }
    }

    public function category(): string
    {
        return $this->category;
    }

    public function itemsFailedCount(): ?int
    {
        return $this->itemsFailedCount;
    }

    /**
     * The ONLY place a sync-run failure summary string is ever
     * assembled, from a fixed template over these structured,
     * closed-vocabulary fields — never string concatenation of
     * anything caller-supplied beyond the closed category set validated
     * above. Never a credential, token, raw provider response body,
     * raw exception message/stack trace, or any confidential client
     * content.
     */
    public function toSummaryText(): string
    {
        $parts = ["category={$this->category}"];

        if ($this->itemsFailedCount !== null) {
            $parts[] = "items_failed_count={$this->itemsFailedCount}";
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{category: string, items_failed_count: ?int}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'items_failed_count' => $this->itemsFailedCount,
        ];
    }
}
