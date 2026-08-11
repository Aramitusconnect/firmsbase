<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\FirmOfficeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmOffice — Mission 2 (MyAttorney Marketplace Core), section 9. See
 * database/migrations/2026_11_10_100002_create_firm_offices_table.php
 * for the full rationale.
 */
class FirmOffice extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'directory_firm_id',
        'label',
        'address_line1',
        'address_line2',
        'city',
        'city_normalized',
        'state',
        'country',
        'postal_code',
        'phone',
        'latitude',
        'longitude',
        'is_primary',
        'appointment_only',
        'published',
        'source_type',
        'source_reference',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_primary' => 'boolean',
            'appointment_only' => 'boolean',
            'published' => 'boolean',
            'source_type' => DataProvenanceSourceType::class,
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): FirmOfficeFactory
    {
        return FirmOfficeFactory::new();
    }

    public function directoryFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
