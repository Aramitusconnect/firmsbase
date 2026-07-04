<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the 3 Phase 4 models carrying a public UUIDv7 identifier via
 * App\Models\Concerns\HasPublicUuid: DocumentRequest,
 * DocumentRequestItem, Document — the conservative UUID scope
 * approved for this phase (the client portal upload flow references
 * these). DocumentVersion, Task, TaskDependency, Deadline,
 * CalendarEvent, NotificationTemplate, NotificationEvent,
 * DocumentChaseRule, DocumentChaseEvent, ReadinessScorecardComponent,
 * MatterReadinessScore, ReadinessScoreEvent deliberately do NOT carry
 * a uuid in Phase 4 (internal/staff-facing only, or accessed only
 * through a parent).
 */
class Phase4PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, class-string>>
     */
    public static function modelProvider(): array
    {
        return [
            'DocumentRequest' => [DocumentRequest::class],
            'DocumentRequestItem' => [DocumentRequestItem::class],
            'Document' => [Document::class],
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

    public function test_task_does_not_carry_a_public_uuid(): void
    {
        $task = \App\Models\Task::factory()->create();

        $this->assertArrayNotHasKey('uuid', $task->getAttributes());
    }

    public function test_deadline_does_not_carry_a_public_uuid(): void
    {
        $deadline = \App\Models\Deadline::factory()->create();

        $this->assertArrayNotHasKey('uuid', $deadline->getAttributes());
    }
}
