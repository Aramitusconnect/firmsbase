<?php

namespace App\Models;

use App\Enums\ApiRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApiRequest — append-only API request audit log. No uuid (mirrors
 * SecurityEvent/PlatformBillingEvent). firm_id is nullable and
 * denormalized from the acting api_key — platform-scoped keys leave it
 * null.
 */
class ApiRequest extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'api_key_id',
        'firm_id',
        'endpoint_identifier',
        'method',
        'status',
        'scope_used',
        'ip_address',
        'response_code',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApiRequestStatus::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
