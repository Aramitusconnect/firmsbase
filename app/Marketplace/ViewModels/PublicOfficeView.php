<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Models\FirmOffice;

/**
 * PublicOfficeView — Mission 2 (MyAttorney Marketplace Core), section
 * 61: explicit public projection of a FirmOffice. Only fields section
 * 9 lists as public-facing are exposed; internal id, provenance
 * (source_type/source_reference), and last_verified_at never reach a
 * template.
 */
final readonly class PublicOfficeView
{
    public function __construct(
        public string $label,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $state,
        public string $country,
        public string $postalCode,
        public ?string $phone,
        public bool $isPrimary,
        public bool $appointmentOnly,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    public static function fromModel(FirmOffice $office): self
    {
        return new self(
            label: $office->label ?? 'Office',
            addressLine1: $office->address_line1,
            addressLine2: $office->address_line2,
            city: $office->city,
            state: $office->state,
            country: $office->country,
            postalCode: $office->postal_code,
            phone: $office->phone,
            isPrimary: $office->is_primary,
            appointmentOnly: $office->appointment_only,
            latitude: $office->latitude !== null ? (float) $office->latitude : null,
            longitude: $office->longitude !== null ? (float) $office->longitude : null,
        );
    }
}
