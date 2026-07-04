<?php

namespace Tests\Feature\Analytics;

use App\Enums\ProductAnalyticsEventType;
use App\Models\Firm;
use App\Services\ProductAnalyticsEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductAnalyticsEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductAnalyticsEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductAnalyticsEventService();
    }

    #[DataProvider('allowedEventTypeProvider')]
    public function test_records_every_allowed_event_type(ProductAnalyticsEventType $type): void
    {
        $firm = Firm::factory()->create();

        $event = $this->service->record($type, $firm);

        $this->assertSame($type, $event->event_type);
        $this->assertDatabaseHas('product_analytics_events', ['firm_id' => $firm->id, 'event_type' => $type->value]);
    }

    public static function allowedEventTypeProvider(): array
    {
        return array_map(fn (ProductAnalyticsEventType $t) => [$t], ProductAnalyticsEventType::cases());
    }

    public function test_count_for_firm_only_counts_matching_event_type(): void
    {
        $firm = Firm::factory()->create();
        $this->service->record(ProductAnalyticsEventType::AiUsed, $firm);
        $this->service->record(ProductAnalyticsEventType::AiUsed, $firm);
        $this->service->record(ProductAnalyticsEventType::MatterCreated, $firm);

        $this->assertSame(2, $this->service->countForFirm($firm, ProductAnalyticsEventType::AiUsed));
    }

    public function test_recording_an_event_type_outside_the_enum_is_impossible_at_the_type_level(): void
    {
        $reflection = new \ReflectionMethod(ProductAnalyticsEventService::class, 'record');
        $eventTypeParam = $reflection->getParameters()[0];

        $this->assertSame(ProductAnalyticsEventType::class, $eventTypeParam->getType()?->getName());
    }
}
