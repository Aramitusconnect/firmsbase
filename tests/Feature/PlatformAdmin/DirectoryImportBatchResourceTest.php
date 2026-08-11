<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ApplyImportBatchAction;
use App\Filament\Actions\Platform\ConfirmImportSourceRightsAction;
use App\Filament\Resources\DirectoryImportBatchResource;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ListDirectoryImportBatches;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ViewDirectoryImportBatch;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Services\MarketplaceCsvIngestionService;
use App\Marketplace\Services\MarketplaceImportApplyService;
use App\Marketplace\Services\MarketplaceImportValidationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\Security\StepUpAuthenticationService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * DirectoryImportBatchResourceTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11 (section 27). ConfirmSourceRights is the
 * explicit self-attestation step; Apply is step-up gated as a
 * defensible bulk-write extension of the mission's named high-risk
 * list, and itself refuses to run without source_rights_confirmed
 * regardless of the step-up gate.
 */
final class DirectoryImportBatchResourceTest extends TestCase
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

    private function resolveSchemaComponents(Action $action): array
    {
        $property = new ReflectionProperty(Action::class, 'schema');
        $property->setAccessible(true);
        $schema = $property->getValue($action);

        return $schema instanceof \Closure ? $schema() : $schema;
    }

    private function assertAuditWritten(string $eventType, int $actorId): void
    {
        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', $eventType)
                ->where('actor_id', $actorId)
                ->first()
        );
        $this->assertNotNull($row, "A security_events row must be written for {$eventType}.");
    }

    /**
     * Builds a batch through the real ingestion+validation pipeline
     * (Mission 2 checkpoint 9) rather than a hand-rolled row — a raw
     * DirectoryImportRow::factory()->valid() row omits derived fields
     * like name_normalized that only MarketplaceImportValidationService
     * computes, and this Admin-surface test cares about the real Apply
     * Action, not re-testing ingestion/validation themselves.
     */
    private function stageAndValidate(string $csv, PlatformAdmin $admin): DirectoryImportBatch
    {
        $batch = app(MarketplaceCsvIngestionService::class)->ingest(
            UploadedFile::fake()->createWithContent('firms.csv', $csv),
            $admin,
        );
        app(MarketplaceImportValidationService::class)->validateBatch($batch->fresh());

        return $batch->fresh();
    }

    // --- Navigation / route-level access control ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(DirectoryImportBatchResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryImportBatchResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_list(): void
    {
        $this->get(DirectoryImportBatchResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $batch = DirectoryImportBatch::factory()->create(['original_filename' => 'directory-import.csv']);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->get(DirectoryImportBatchResource::getUrl())->assertOk()->assertSee('directory-import.csv');
        $this->get(DirectoryImportBatchResource::getUrl('view', ['record' => $batch]))->assertOk();
    }

    // --- Confirm Source Rights ---

    public function test_confirm_source_rights_action_is_visible_only_until_confirmed(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $batch = DirectoryImportBatch::factory()->create();

        $test = Livewire::test(ListDirectoryImportBatches::class);
        $test->assertTableActionVisible(ConfirmImportSourceRightsAction::getDefaultName(), $batch);
        $test->mountTableAction(ConfirmImportSourceRightsAction::getDefaultName(), $batch);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $batch->refresh();
        $this->assertTrue($batch->source_rights_confirmed);

        $test2 = Livewire::test(ListDirectoryImportBatches::class);
        $test2->assertTableActionHidden(ConfirmImportSourceRightsAction::getDefaultName(), $batch);
    }

    // --- Apply (step-up gated) ---

    public function test_apply_action_requires_the_password_field_without_a_recent_step_up_verification(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $batch = DirectoryImportBatch::factory()->create(['status' => DirectoryImportBatchStatus::Validated]);

        $action = ApplyImportBatchAction::make();
        $this->assertCount(1, $this->resolveSchemaComponents($action));
    }

    public function test_apply_action_refuses_to_run_without_source_rights_confirmed_even_with_step_up(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $batch = DirectoryImportBatch::factory()->create(['status' => DirectoryImportBatchStatus::Validated, 'source_rights_confirmed' => false]);

        $test = Livewire::test(ListDirectoryImportBatches::class);
        $test->mountTableAction(ApplyImportBatchAction::getDefaultName(), $batch);
        $test->callMountedTableAction();
        $test->assertNotified();

        $batch->refresh();
        $this->assertSame(DirectoryImportBatchStatus::SourceApprovalRequired, $batch->status);
        $this->assertNotSame(DirectoryImportBatchStatus::Applied, $batch->status);
    }

    public function test_apply_action_creates_a_draft_listing_for_every_valid_row_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $header = "legal_name,display_name,phone,website,public_email,description,city,state,postal_code,founding_year\n";
        $csv = $header
            ."Acme Legal PLLC,Acme Legal,5555550100,,,,,,,\n"
            ."Second Firm PLLC,Second Firm,5555550199,,,,,,,\n";

        $batch = $this->stageAndValidate($csv, $actor);
        app(MarketplaceImportApplyService::class)->confirmSourceRights($batch);

        $test = Livewire::test(ViewDirectoryImportBatch::class, ['record' => $batch->fresh()->uuid]);
        $test->mountAction(ApplyImportBatchAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $batch->refresh();
        $this->assertSame(DirectoryImportBatchStatus::Applied, $batch->status);
        $this->assertSame(2, $batch->applied_rows);
        $this->assertAuditWritten('marketplace_import_applied', $actor->id);

        $this->assertSame(
            2,
            $batch->rows()->where('status', DirectoryImportRowStatus::Applied)->count()
        );
    }

    public function test_apply_action_is_hidden_once_already_applied(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $batch = DirectoryImportBatch::factory()->create(['status' => DirectoryImportBatchStatus::Applied]);

        $test = Livewire::test(ListDirectoryImportBatches::class);
        $test->assertTableActionHidden(ApplyImportBatchAction::getDefaultName(), $batch);
    }
}
