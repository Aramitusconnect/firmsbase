<?php

namespace App\ValueObjects;

use App\Enums\FormMappingContentStatus;
use App\Enums\FormDraftValueSource;

class FormMappingResolutionResult
{
    public function __construct(
        public readonly string $fieldCode,
        public readonly ?string $value,
        public readonly FormDraftValueSource $source,
        public readonly ?FormMappingContentStatus $contentStatus,
        public readonly ?int $formMappingRuleId,
    ) {
    }
}
