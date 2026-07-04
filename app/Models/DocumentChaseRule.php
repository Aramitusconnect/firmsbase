<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\DocumentChaseRuleStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentChaseRule — a firm may define multiple named rules (per
 * approved decision). DocumentChaseSchedulerService reads the
 * schedule/escalation fields; DocumentChaseService applies them
 * against a specific DocumentRequestItem. No uuid — internal firm
 * configuration only.
 */
class DocumentChaseRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'name',
        'status',
        'applies_to',
        'reminder_offsets_days',
        'max_reminders',
        'escalate_after_days',
        'escalate_to_user_id',
        'channel',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentChaseRuleStatus::class,
            'reminder_offsets_days' => 'array',
            'channel' => ConsentChannel::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function escalateToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalate_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentChaseEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === DocumentChaseRuleStatus::Active;
    }
}
