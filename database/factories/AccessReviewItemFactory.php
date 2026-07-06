<?php

namespace Database\Factories;

use App\Enums\AccessReviewItemDecision;
use App\Models\AccessReview;
use App\Models\AccessReviewItem;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessReviewItem>
 */
class AccessReviewItemFactory extends Factory
{
    protected $model = AccessReviewItem::class;

    public function definition(): array
    {
        return [
            'access_review_id' => AccessReview::factory(),
            'subject_type' => PlatformAdmin::class,
            'subject_id' => PlatformAdmin::factory(),
            'subject_snapshot_json' => ['note' => 'snapshot at review time'],
            'decision' => AccessReviewItemDecision::Pending,
        ];
    }

    public function forReview(AccessReview $review): static
    {
        return $this->state(fn () => ['access_review_id' => $review->id]);
    }

    public function decided(AccessReviewItemDecision $decision, PlatformAdmin $reviewer): static
    {
        return $this->state(fn () => [
            'decision' => $decision,
            'reviewed_by_platform_admin_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }
}
