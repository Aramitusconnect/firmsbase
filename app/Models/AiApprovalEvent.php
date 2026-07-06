<?php

namespace App\Models;

use App\Enums\AiApprovalEventType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiApprovalEvent — append-only (project rule 9): no updated_at, and
 * the model's booted() hook throws on any update/delete of an
 * existing row, mirroring WebhookEvent's exact immutability pattern.
 * The only writer is AiApprovalWorkflowService.
 */
class AiApprovalEvent extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'ai_approval_events';

    const UPDATED_AT = null;

    protected $fillable = [
        'ai_approval_request_id',
        'firm_id',
        'event_type',
        'actor_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AiApprovalEventType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('ai_approval_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('ai_approval_events is append-only and cannot be deleted.');
        });
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(AiApprovalRequest::class, 'ai_approval_request_id');
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
