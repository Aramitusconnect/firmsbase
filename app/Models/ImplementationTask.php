<?php

namespace App\Models;

use App\Enums\ImplementationTaskStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ImplementationTask — mirrors ActivationChecklistItem's shape and
 * purpose exactly (no firm_id of its own, scoped transitively through
 * implementation_project_id).
 */
class ImplementationTask extends Model
{
    use HasFactory, HasPublicUuid;

    public const TASK_KEYS = [
        'kickoff',
        'import_planning',
        'template_selection',
        'user_setup',
        'client_portal_setup',
        'email_verification',
        'consent_capture_setup',
        'payment_mode_confirmation',
        'staff_training',
        'go_live_review',
        'success_review_30_day',
    ];

    protected $fillable = [
        'implementation_project_id',
        'task_key',
        'status',
        'is_required',
        'completed_by',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImplementationTaskStatus::class,
            'is_required' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function implementationProject(): BelongsTo
    {
        return $this->belongsTo(ImplementationProject::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'completed_by');
    }
}
