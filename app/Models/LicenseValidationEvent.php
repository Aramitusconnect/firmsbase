<?php

namespace App\Models;

use App\Enums\LicenseValidationEventType;
use App\Enums\LicenseValidationResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LicenseValidationEvent — append-only (no updated_at, booted() hook
 * throws on update/delete). The ONLY writer is
 * LicenseFileValidationService::validate() — every call writes exactly
 * one row here regardless of outcome.
 */
class LicenseValidationEvent extends Model
{
    use HasFactory, \App\Models\Concerns\HasPublicUuid;

    protected $table = 'license_validation_events';

    const UPDATED_AT = null;

    protected $fillable = [
        'license_file_id',
        'firm_id',
        'event_type',
        'result',
        'detail',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => LicenseValidationEventType::class,
            'result' => LicenseValidationResult::class,
            'validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('license_validation_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('license_validation_events is append-only and cannot be deleted.');
        });
    }

    public function licenseFile(): BelongsTo
    {
        return $this->belongsTo(LicenseFile::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
