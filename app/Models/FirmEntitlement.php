<?php

namespace App\Models;

use App\Enums\EntitlementSource;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FirmEntitlement extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'module_code',
        'enabled',
        'source',
        'settings_json',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'source' => EntitlementSource::class,
            'settings_json' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalog::class, 'module_code', 'module_code');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FirmEntitlementEvent::class);
    }

    /**
     * Is this record currently within its active window (if one is
     * set)? Does not by itself decide precedence — see EntitlementService.
     */
    public function isWithinActiveWindow(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if ($this->starts_at && $this->starts_at->isAfter($at)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isBefore($at)) {
            return false;
        }

        return true;
    }
}
