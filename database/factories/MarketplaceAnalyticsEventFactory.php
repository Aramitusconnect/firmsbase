<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceAnalyticsEvent>
 */
class MarketplaceAnalyticsEventFactory extends Factory
{
    protected $model = MarketplaceAnalyticsEvent::class;

    public function definition(): array
    {
        return [
            'event_type' => MarketplaceAnalyticsEventType::FirmProfileViewed,
            'subject_type' => DirectoryFirm::class,
            'subject_id' => DirectoryFirm::factory(),
            'dimensions' => null,
            'occurred_at' => now(),
        ];
    }

    public function firmProfileViewed(): static
    {
        return $this->state(fn () => ['event_type' => MarketplaceAnalyticsEventType::FirmProfileViewed]);
    }

    public function attorneyProfileViewed(): static
    {
        return $this->state(fn () => [
            'event_type' => MarketplaceAnalyticsEventType::AttorneyProfileViewed,
            'subject_type' => DirectoryAttorney::class,
            'subject_id' => DirectoryAttorney::factory(),
        ]);
    }

    public function searchPerformed(array $dimensions = []): static
    {
        return $this->state(fn () => [
            'event_type' => MarketplaceAnalyticsEventType::SearchPerformed,
            'subject_type' => null,
            'subject_id' => null,
            'dimensions' => $dimensions,
        ]);
    }
}
