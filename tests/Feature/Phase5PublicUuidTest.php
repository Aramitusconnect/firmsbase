<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\StatusPageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the 2 Phase 5 models carrying a public UUIDv7 identifier via
 * App\Models\Concerns\HasPublicUuid: StatusPageEvent, MaintenanceWindow
 * — the conservative UUID scope approved for this phase (both are
 * potentially public/status-page-facing). FirmActivationEvent,
 * HealthCheck, BackupRestoreTest, IncidentEvent, PilotFeedbackItem
 * deliberately do NOT carry a uuid in Phase 5 (internal-only).
 */
class Phase5PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, class-string>>
     */
    public static function modelProvider(): array
    {
        return [
            'StatusPageEvent' => [StatusPageEvent::class],
            'MaintenanceWindow' => [MaintenanceWindow::class],
        ];
    }

    #[DataProvider('modelProvider')]
    public function test_model_receives_a_uuid_on_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertNotNull($model->uuid);
        $this->assertNotEmpty($model->uuid);
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_a_valid_uuidv7(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $model->uuid,
            "{$modelClass}::uuid must be a version-7 UUID"
        );
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_immutable_after_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('uuid is immutable');

        $model->uuid = (string) \Illuminate\Support\Str::uuid7();
        $model->save();
    }

    #[DataProvider('modelProvider')]
    public function test_route_key_name_is_uuid(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertSame('uuid', $model->getRouteKeyName());
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_column_is_unique(string $modelClass): void
    {
        $first = $modelClass::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $modelClass::factory()->create(['uuid' => $first->uuid]);
    }

    public function test_health_check_does_not_carry_a_public_uuid(): void
    {
        $check = \App\Models\HealthCheck::factory()->create();

        $this->assertArrayNotHasKey('uuid', $check->getAttributes());
    }

    public function test_pilot_feedback_item_does_not_carry_a_public_uuid(): void
    {
        $item = \App\Models\PilotFeedbackItem::factory()->create();

        $this->assertArrayNotHasKey('uuid', $item->getAttributes());
    }
}
