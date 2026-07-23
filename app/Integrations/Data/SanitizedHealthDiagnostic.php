<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * SanitizedHealthDiagnostic — the ONLY shape any
 * App\Integrations\Services\HealthStateService::record*Error()/
 * recordRateLimited() call may accept (Checkpoint 8,
 * agent-8f-health-state-design.md §7; frozen as authoritative over §4's
 * plain-string sketch by agent-8h-architecture-security-review.md §1
 * item 6). A small, closed-shape value object — never a free-text
 * string built by a caller — mirroring
 * App\Integrations\Exceptions\SanitizedProviderHttpException's
 * closed-category-validated-in-the-constructor discipline, applied one
 * layer downstream. `recordSuccess()` needs no diagnostic parameter at
 * all (there is nothing to sanitize on a success signal beyond the two
 * bare IDs).
 *
 * Allowlist enforced structurally by this constructor (never widen
 * without also widening HealthStateService's own closed
 * `last_failure_category` column vocabulary): $category must be one of
 * rate_limited|credential_error|scope_error|provider_error; $operationLabel
 * must be one of a small, developer-controlled, closed set — NEVER
 * provider-supplied free text.
 */
final class SanitizedHealthDiagnostic
{
    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_CREDENTIAL_ERROR = 'credential_error';

    public const CATEGORY_SCOPE_ERROR = 'scope_error';

    public const CATEGORY_PROVIDER_ERROR = 'provider_error';

    private const VALID_CATEGORIES = [
        self::CATEGORY_RATE_LIMITED,
        self::CATEGORY_CREDENTIAL_ERROR,
        self::CATEGORY_SCOPE_ERROR,
        self::CATEGORY_PROVIDER_ERROR,
    ];

    public const OPERATION_HEALTH_CHECK = 'health_check';

    public const OPERATION_TOKEN_REFRESH = 'token_refresh';

    public const OPERATION_PULL_SYNC = 'pull_sync';

    public const OPERATION_PUSH_SYNC = 'push_sync';

    public const OPERATION_WEBHOOK_PROCESS = 'webhook_process';

    public const OPERATION_OUTBOX_DISPATCH = 'outbox_dispatch';

    private const VALID_OPERATION_LABELS = [
        self::OPERATION_HEALTH_CHECK,
        self::OPERATION_TOKEN_REFRESH,
        self::OPERATION_PULL_SYNC,
        self::OPERATION_PUSH_SYNC,
        self::OPERATION_WEBHOOK_PROCESS,
        self::OPERATION_OUTBOX_DISPATCH,
    ];

    public function __construct(
        private readonly string $category,
        private readonly string $operationLabel,
        private readonly ?int $httpStatus = null,
        private readonly ?Carbon $providerResetAt = null,
    ) {
        if (! in_array($category, self::VALID_CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown health-diagnostic category: \"{$category}\".");
        }

        if (! in_array($operationLabel, self::VALID_OPERATION_LABELS, true)) {
            throw new InvalidArgumentException("Unknown health-diagnostic operation label: \"{$operationLabel}\".");
        }
    }

    public function category(): string
    {
        return $this->category;
    }

    public function operationLabel(): string
    {
        return $this->operationLabel;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function providerResetAt(): ?Carbon
    {
        return $this->providerResetAt;
    }

    /**
     * The ONLY place sanitized_diagnostic_summary text is ever
     * assembled, from a fixed template over these structured,
     * closed-vocabulary fields — never string concatenation of
     * anything caller-supplied beyond the closed sets validated above.
     * Never a credential, token, raw provider response body/header, raw
     * exception message/stack trace, or any confidential client
     * content — see agent-8f-health-state-design.md §7's explicit
     * allowlist/denylist.
     */
    public function toSummaryText(): string
    {
        $parts = ["category={$this->category}", "operation={$this->operationLabel}"];

        if ($this->httpStatus !== null) {
            $parts[] = "http_status={$this->httpStatus}";
        }

        if ($this->providerResetAt !== null) {
            $parts[] = 'resets_at='.$this->providerResetAt->toIso8601String();
        }

        return implode(', ', $parts);
    }
}
