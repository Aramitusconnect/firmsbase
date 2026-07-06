<?php

namespace Database\Factories;

use App\Enums\AccessReviewScope;
use App\Enums\AccessReviewStatus;
use App\Models\AccessReview;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessReview>
 *
 * Defaults to a platform-scope review (firm_id null). Use ->forFirm()
 * for a firm-scope review (e.g. FirmAdmins).
 */
class AccessReviewFactory extends Factory
{
    protected $model = AccessReview::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'scope' => AccessReviewScope::PlatformAdmins,
            'status' => AccessReviewStatus::Draft,
            'initiated_by_platform_admin_id' => PlatformAdmin::factory(),
            'initiated_at' => now(),
        ];
    }

    public function forFirm(Firm $firm, AccessReviewScope $scope = AccessReviewScope::FirmAdmins): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id, 'scope' => $scope]);
    }
}
