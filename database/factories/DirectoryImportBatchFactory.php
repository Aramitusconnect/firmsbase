<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryImportBatch>
 */
class DirectoryImportBatchFactory extends Factory
{
    protected $model = DirectoryImportBatch::class;

    public function definition(): array
    {
        return [
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
            'original_filename' => 'firms.csv',
            'status' => DirectoryImportBatchStatus::Staged,
            'source_rights_confirmed' => false,
        ];
    }

    public function sourceRightsConfirmed(): static
    {
        return $this->state(fn () => ['source_rights_confirmed' => true]);
    }
}
