<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\DownloadImportBatchErrorCsvAction;
use App\Filament\Actions\Platform\UploadDirectoryImportBatchAction;
use App\Filament\Resources\DirectoryImportBatchResource;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ListDirectoryImportBatches;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ViewDirectoryImportBatch;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Services\MarketplaceCsvIngestionService;
use App\Marketplace\Services\MarketplaceImportDuplicateDetectionService;
use App\Marketplace\Services\MarketplaceImportValidationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DirectoryImportBatchOperationsTest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT6). Proves: the newly-wired "New
 * Import" action ingests+validates+detects duplicates in one step
 * (landing in Previewed), the Preview section renders real
 * MarketplaceImportApplyService::preview() counts (a method that
 * existed since Mission 2 but was never rendered), and the error-CSV
 * download.
 */
final class DirectoryImportBatchOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function sampleCsv(): string
    {
        return "legal_name,display_name,phone,website,public_email,description,city,state,postal_code,founding_year\n"
            ."Valid Firm PLLC,Valid Firm,5551112222,https://valid.example.com,contact@valid.example.com,A firm.,Detroit,MI,48226,2010\n";
    }

    public function test_new_import_action_ingests_validates_and_lands_in_previewed(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDirectoryImportBatches::class);
        $test->mountAction(UploadDirectoryImportBatchAction::getDefaultName());
        $test->set('mountedActions.0.data.file', UploadedFile::fake()->createWithContent('firms.csv', $this->sampleCsv()));
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $batch = DirectoryImportBatch::query()->where('original_filename', 'firms.csv')->latest('id')->first();
        $this->assertNotNull($batch, 'The New Import action must create a DirectoryImportBatch.');
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(DirectoryImportBatchStatus::Previewed, $batch->status);
        $this->assertSame($admin->id, $batch->created_by_platform_admin_id);
    }

    public function test_invalid_csv_is_rejected_with_a_clear_error(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDirectoryImportBatches::class);
        $test->mountAction(UploadDirectoryImportBatchAction::getDefaultName());
        $test->set('mountedActions.0.data.file', UploadedFile::fake()->createWithContent('bad.csv', "not,the,right,headers\n1,2,3,4\n"));
        $test->callMountedAction();

        $this->assertSame(0, DirectoryImportBatch::query()->where('original_filename', 'bad.csv')->count());
    }

    public function test_preview_section_renders_real_creatable_and_invalid_counts(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $batch = app(MarketplaceCsvIngestionService::class)->ingest(
            UploadedFile::fake()->createWithContent('preview.csv', $this->sampleCsv()),
            $admin,
        );
        $batch = app(MarketplaceImportValidationService::class)->validateBatch($batch);
        $batch = app(MarketplaceImportDuplicateDetectionService::class)->detectBatch($batch);

        $response = $this->get(DirectoryImportBatchResource::getUrl('view', ['record' => $batch]));

        $response->assertOk();
        $response->assertSee('Would Create');
        $response->assertSee('1'); // one creatable row
    }

    public function test_download_error_csv_action_is_visible_only_when_there_are_invalid_or_duplicate_rows(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $cleanBatch = app(MarketplaceCsvIngestionService::class)->ingest(
            UploadedFile::fake()->createWithContent('clean.csv', $this->sampleCsv()),
            $admin,
        );
        app(MarketplaceImportValidationService::class)->validateBatch($cleanBatch);

        $test = Livewire::test(ViewDirectoryImportBatch::class, ['record' => $cleanBatch->uuid]);
        $test->assertActionHidden(DownloadImportBatchErrorCsvAction::getDefaultName());

        $badCsv = "legal_name,display_name,phone,website,public_email,description,city,state,postal_code,founding_year\n"
            .",,,,,,,,not-a-year\n";
        $invalidBatch = app(MarketplaceCsvIngestionService::class)->ingest(
            UploadedFile::fake()->createWithContent('invalid.csv', $badCsv),
            $admin,
        );
        app(MarketplaceImportValidationService::class)->validateBatch($invalidBatch);

        $test2 = Livewire::test(ViewDirectoryImportBatch::class, ['record' => $invalidBatch->fresh()->uuid]);
        $test2->assertActionVisible(DownloadImportBatchErrorCsvAction::getDefaultName());
    }

    public function test_sales_rep_cannot_upload_an_import_batch(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->get(DirectoryImportBatchResource::getUrl('index'))->assertForbidden();
    }
}
