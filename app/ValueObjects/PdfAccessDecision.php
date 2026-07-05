<?php

namespace App\ValueObjects;

/**
 * PdfAccessDecision — the explicit, never-implicit output of
 * PdfDownloadPolicyService. A download is never allowed silently: the
 * caller must always have a PdfAccessDecision in hand, and
 * PdfViewEventService logs a separate DownloadAllowed/DownloadDenied
 * row based on it.
 */
final readonly class PdfAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }

    public static function allow(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
