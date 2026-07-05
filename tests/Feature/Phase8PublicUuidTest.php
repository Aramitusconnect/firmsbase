<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ExportFile;
use App\Models\ExportJob;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRollbackRecord;
use App\Models\ImportRow;
use App\Models\MigrationProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the approved Phase 8 uuid decision (correction #9): 8
 * workflow models carry a public uuid, while api_key_scopes,
 * api_requests, import_errors, and import_audit_events (grant/audit-
 * style, never addressed individually) do not.
 */
class Phase8PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_has_a_public_uuid(string $modelClass): void
    {
        $instance = $modelClass::factory()->create();

        $this->assertNotNull($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [ApiKey::class],
            [ImportBatch::class],
            [ImportMapping::class],
            [ImportRow::class],
            [ExportJob::class],
            [ExportFile::class],
            [MigrationProject::class],
            [ImportRollbackRecord::class],
        ];
    }

    #[DataProvider('noUuidTableProvider')]
    public function test_table_has_no_uuid_column(string $table): void
    {
        $columns = Schema::getColumnListing($table);

        $this->assertNotContains('uuid', $columns);
    }

    public static function noUuidTableProvider(): array
    {
        return [
            ['api_key_scopes'],
            ['api_requests'],
            ['import_errors'],
            ['import_audit_events'],
        ];
    }
}
