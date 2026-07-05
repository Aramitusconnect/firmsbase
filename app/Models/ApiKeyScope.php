<?php

namespace App\Models;

use App\Enums\ApiKeyScopeCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApiKeyScope — a grant row over the fixed ApiKeyScopeCode enum. No
 * uuid (mirrors Phase 7's PlatformRole precedent).
 */
class ApiKeyScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'api_key_id',
        'scope_code',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_code' => ApiKeyScopeCode::class,
            'granted_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
