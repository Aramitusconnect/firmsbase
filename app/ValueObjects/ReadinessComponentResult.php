<?php

namespace App\ValueObjects;

/**
 * ReadinessComponentResult — returned by each registered readiness
 * component callable in ReadinessScorecardRegistry when evaluated
 * against a given Matter. satisfied drives whether the component
 * counts toward MatterReadinessService's aggregate score.
 */
final readonly class ReadinessComponentResult
{
    public function __construct(
        public string $componentKey,
        public bool $satisfied,
        public ?string $detail = null,
    ) {
    }
}
