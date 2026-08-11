<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Enums\ConsultationMode;

/**
 * SearchCriteria — Mission 2 (MyAttorney Marketplace Core), sections
 * 32-34. Every field is optional (a blank search returns every
 * published, indexable listing) — the search must work through
 * normalized text/geography even when no coordinates exist (section
 * 34: "do not make maps mandatory").
 */
final readonly class SearchCriteria
{
    public function __construct(
        public ?string $name = null,
        public ?string $practiceAreaSlug = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $languageCode = null,
        public bool $acceptingInquiriesOnly = false,
        public ?ConsultationMode $consultationMode = null,
        public ?float $originLatitude = null,
        public ?float $originLongitude = null,
    ) {}

    public static function fromArray(array $input): self
    {
        $mode = isset($input['consultation_mode']) ? ConsultationMode::tryFrom((string) $input['consultation_mode']) : null;

        return new self(
            name: self::nullableString($input['name'] ?? null),
            practiceAreaSlug: self::nullableString($input['practice_area'] ?? null),
            city: self::nullableString($input['city'] ?? null),
            state: self::nullableString($input['state'] ?? null),
            postalCode: self::nullableString($input['postal_code'] ?? null),
            languageCode: self::nullableString($input['language'] ?? null),
            acceptingInquiriesOnly: (bool) ($input['accepting_inquiries'] ?? false),
            consultationMode: $mode,
            originLatitude: isset($input['lat']) ? (float) $input['lat'] : null,
            originLongitude: isset($input['lng']) ? (float) $input['lng'] : null,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
