<?php

namespace App\Models;

use App\Enums\ConflictCheckResultStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ConflictCheckResult — deliberately does NOT use BelongsToTenant. No
 * firm_id column of its own; isolation is transitive through
 * conflict_check_run_id -> conflict_check_runs.firm_id.
 *
 * matched_type/matched_id form a lightweight polymorphic reference
 * (client|contact|party|matter_party|free_text) rather than several
 * nullable FK columns — see the migration comment. matched_id has no
 * database FK; matched_type disambiguates at the application layer.
 */
class ConflictCheckResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'conflict_check_run_id',
        'matched_type',
        'matched_id',
        'matched_value',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConflictCheckResultStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function conflictCheckRun(): BelongsTo
    {
        return $this->belongsTo(ConflictCheckRun::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isFreeText(): bool
    {
        return $this->matched_type === 'free_text';
    }

    public function needsReview(): bool
    {
        return $this->status === ConflictCheckResultStatus::PossibleMatch;
    }
}
