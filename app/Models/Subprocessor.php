<?php

namespace App\Models;

use App\Enums\SubprocessorStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subprocessor — customer-facing disclosure entries linked to
 * vendor_register (approved decision #6). Mutable, same reasoning as
 * Vendor.
 */
class Subprocessor extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'vendor_register_id',
        'disclosed_name',
        'service_purpose',
        'data_categories_json',
        'regions_json',
        'is_publicly_disclosed',
        'disclosure_effective_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data_categories_json' => 'array',
            'regions_json' => 'array',
            'is_publicly_disclosed' => 'boolean',
            'disclosure_effective_at' => 'datetime',
            'status' => SubprocessorStatus::class,
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_register_id');
    }
}
