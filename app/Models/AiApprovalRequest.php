<?php

namespace App\Models;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AiApprovalRequest — approved decision #4: encrypted_snapshot_ciphertext
 * is HIDDEN from serialization, matching FirmAiProviderKey's exact
 * discipline ("do not expose encrypted snapshot or key ciphertext in
 * model serialization"). status is mutable (pending -> approved/
 * rejected) via AiApprovalWorkflowService only; every other column is
 * set once at submission and never changed. draft_label is always
 * 'ai_generated_draft' (project rule 21) — never set to anything else
 * by any writer.
 */
class AiApprovalRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'ai_approval_requests';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'requested_by',
        'ai_usage_event_id',
        'category',
        'status',
        'draft_label',
        'encrypted_snapshot_ciphertext',
        'encryption_key_id',
        'resolved_at',
    ];

    protected $hidden = [
        'encrypted_snapshot_ciphertext',
    ];

    protected $attributes = [
        'status' => 'pending',
        'draft_label' => 'ai_generated_draft',
    ];

    protected function casts(): array
    {
        return [
            'category' => AiApprovalCategory::class,
            'status' => AiApprovalRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function usageEvent(): BelongsTo
    {
        return $this->belongsTo(AiUsageEvent::class, 'ai_usage_event_id');
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiApprovalEvent::class);
    }

    public function isPending(): bool
    {
        return $this->status === AiApprovalRequestStatus::Pending;
    }
}
