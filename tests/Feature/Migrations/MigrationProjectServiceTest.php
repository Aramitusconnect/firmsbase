<?php

namespace Tests\Feature\Migrations;

use App\Enums\MigrationProjectStatus;
use App\Enums\MigrationSourceType;
use App\Models\Firm;
use App\Services\MigrationProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MigrationProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    private MigrationProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MigrationProjectService();
    }

    #[DataProvider('sourceTypeProvider')]
    public function test_migration_project_supports_every_approved_source_type(MigrationSourceType $type): void
    {
        $firm = Firm::factory()->create();

        $project = $this->service->create($firm, $type);

        $this->assertSame($type, $project->source_type);
        $this->assertSame(MigrationProjectStatus::Draft, $project->status);
    }

    public static function sourceTypeProvider(): array
    {
        return array_map(fn (MigrationSourceType $t) => [$t], MigrationSourceType::cases());
    }

    public function test_start_and_complete_track_timestamps(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->service->create($firm, MigrationSourceType::ClioExport);

        $started = $this->service->start($project);
        $this->assertSame(MigrationProjectStatus::InProgress, $started->status);
        $this->assertNotNull($started->started_at);

        $completed = $this->service->complete($started);
        $this->assertSame(MigrationProjectStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }
}
