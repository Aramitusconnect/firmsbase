<?php

namespace App\Services\Automation;

use Illuminate\Database\Eloquent\Model;

/**
 * AutomationActionOutcome — Event-Driven Automation Engine, item 9. The
 * return value every AutomationActionHandler produces on success (in the
 * broad sense — Succeeded and Skipped are both "ran to completion, no
 * error"). A handler throws AutomationActionTransientException/
 * AutomationActionPermanentException for genuine failures instead of
 * returning an outcome for those — see those exceptions' own docblocks.
 */
final readonly class AutomationActionOutcome
{
    private function __construct(
        public bool $skipped,
        public ?string $message,
        public ?string $resultReferenceType,
        public ?int $resultReferenceId,
    ) {}

    public static function succeeded(?Model $resultReference = null, ?string $message = null): self
    {
        return new self(false, $message, $resultReference?->getMorphClass(), $resultReference?->getKey());
    }

    /**
     * A legitimate, non-error terminal state: the action's precondition
     * genuinely didn't hold for this event (e.g. no matter/assigned
     * attorney to notify) — never guessed at with a fallback recipient,
     * never silently swallowed as if it were a success.
     */
    public static function skipped(string $reason): self
    {
        return new self(true, $reason, null, null);
    }
}
