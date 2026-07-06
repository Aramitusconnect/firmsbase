<?php

namespace App\Models;

use App\Enums\AccessReviewItemDecision;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AccessReviewItem — no firm_id of its own (scoped via access_review_id).
 * decision defaults to Pending. Once a non-Pending decision is recorded,
 * the row freezes (matches the project's "access review items after
 * completion" guidance) — Revoke/Modify decisions are RECORD-ONLY
 * (approved decision #10): recording one here never itself revokes the
 * subject; that stays a manual or future separately-scoped action.
 */
class AccessReviewItem extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'access_review_id',
        'subject_type',
        'subject_id',
        'subject_snapshot_json',
        'decision',
        'reviewed_by_platform_admin_id',
        'reviewed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subject_snapshot_json' => 'array',
            'decision' => AccessReviewItemDecision::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $item) {
            $original = $item->getOriginal('decision');

            if ($original !== null && $original !== AccessReviewItemDecision::Pending) {
                throw new \LogicException('access_review_items rows are frozen once a decision has been recorded.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('access_review_items rows can never be deleted.');
        });
    }

    public function accessReview(): BelongsTo
    {
        return $this->belongsTo(AccessReview::class);
    }

    public function reviewedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by_platform_admin_id');
    }

    public function isPending(): bool
    {
        return $this->decision === AccessReviewItemDecision::Pending;
    }
}
