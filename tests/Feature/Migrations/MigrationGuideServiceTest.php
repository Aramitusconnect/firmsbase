<?php

namespace Tests\Feature\Migrations;

use App\Enums\MigrationSourceType;
use App\Services\MigrationGuideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MigrationGuideServiceTest extends TestCase
{
    use RefreshDatabase;

    private MigrationGuideService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MigrationGuideService();
    }

    #[DataProvider('sourceTypeProvider')]
    public function test_every_source_type_has_non_empty_guidance_steps(MigrationSourceType $type): void
    {
        $steps = $this->service->stepsFor($type);

        $this->assertNotEmpty($steps);
        foreach ($steps as $step) {
            $this->assertIsString($step);
        }
    }

    public static function sourceTypeProvider(): array
    {
        return array_map(fn (MigrationSourceType $t) => [$t], MigrationSourceType::cases());
    }

    public function test_guide_service_never_references_a_real_http_client(): void
    {
        $source = file_get_contents(app_path('Services/MigrationGuideService.php'));

        $this->assertStringNotContainsString('GuzzleHttp', $source);
        $this->assertStringNotContainsString('Http::', $source);
    }
}
