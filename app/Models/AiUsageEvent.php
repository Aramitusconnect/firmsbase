<?php

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AiUsageEvent — append-only (project rule 8): no updated_at, and the
 * model's booted() hook throws on any update/delete of an existing
 * row, mirroring WebhookEvent/TrustLedgerEntry's exact immutability
 * pattern. The only writer is AiUsageRecorderService.
 */
class AiUsageEvent extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'ai_usage_events';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'user_id',
        'matter_id',
        'ai_mode',
        'provider',
        'model',
        'tokens_in',
        'tokens_out',
        'cost_cents',
        'approval_required',
        'action_type',
    ];

    protected function casts(): array
    {
        return [
            'ai_mode' => AiMode::class,
            'provider' => AiProvider::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost_cents' => 'integer',
            'approval_required' => 'boolean',
            'action_type' => AiUsageActionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('ai_usage_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('ai_usage_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(AiApprovalRequest::class);
    }

    public function toolActions(): HasMany
    {
        return $this->hasMany(AiToolAction::class);
    }
}
