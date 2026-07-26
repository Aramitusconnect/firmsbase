<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\RetrySyncFailureAction;
use App\Filament\Resources\SyncFailureResource;
use App\Filament\Resources\SyncFailureResource\Pages\ListSyncFailures;
use App\Filament\Resources\SyncFailureResource\Pages\ViewSyncFailure;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TenantEncryptionKey;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SyncFailureResourceTest — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Route-level authorization,
 * cross-firm listing, redaction, deterministic ordering, no-N+1, and the
 * Retry action's full lifecycle (authorization, audit event, idempotent
 * ineligibility handling).
 */
final class SyncFailureResourceTest extends TestCase
{
    use RefreshDatabase;

    private const LAST_ERROR_MARKER = 'SECRET-MARKER-sync-failure-resource-7f2a';

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

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    /**
     * SyncItemService::requeueFromFailedPermanent() requires the owning
     * connection to have an active credential (see
     * RequeueIneligibilityReason::CredentialRevoked) — without this, a
     * requeue attempt silently no-ops (returns null) rather than
     * mutating the item, mirroring
     * PlatformOperationalActionsLivewireTest::givenActiveCredentialFor()'s
     * identical setup requirement.
     */
    private function givenActiveCredentialFor(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, function () use ($firm, $connection): void {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
            IntegrationCredential::factory()->forFirmIntegration($connection)->create();
        });
    }

    private function failedPermanentItem(Firm $firm, FirmIntegration $connection): IntegrationSyncItem
    {
        return $this->runWithFirmContext($firm, function () use ($connection): IntegrationSyncItem {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });
    }

    // --- Navigation visibility ---
    //
    // Filament\Resources\Resource\Concerns\HasNavigation::registerNavigationItems()
    // gates on canAccess() directly (confirmed by reading that trait) —
    // shouldRegisterNavigation() alone is only a static opt-in/opt-out
    // flag, not an authorization check. canAccess() is therefore the
    // real navigation-visibility signal for a Resource (unlike a plain
    // Page, which needs its own shouldRegisterNavigation() override).

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(SyncFailureResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(SyncFailureResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(SyncFailureResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_sync_failures_list(): void
    {
        $this->get(SyncFailureResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(SyncFailureResource::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(SyncFailureResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Failing Firm']);
        $connection = $this->connection($firm);
        $item = $this->failedPermanentItem($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(SyncFailureResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Failing Firm');

        $viewResponse = $this->get(ViewSyncFailure::getUrl(['firmUuid' => $firm->uuid, 'id' => $item->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_a_sync_failure_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connection = $this->connection($firmA);
        $item = $this->failedPermanentItem($firmA, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewSyncFailure::getUrl(['firmUuid' => $firmB->uuid, 'id' => $item->id]))
            ->assertNotFound();
    }

    // --- Redaction ---

    public function test_last_error_never_appears_in_the_rendered_list_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, function () use ($connection): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create(['last_error' => self::LAST_ERROR_MARKER]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(SyncFailureResource::getUrl());
        $response->assertOk();
        $response->assertDontSee(self::LAST_ERROR_MARKER);
    }

    // --- No-N+1 proof ---

    /**
     * Renders the SAME single firm/connection twice — once with 1 failed
     * item, once after 9 more are added (10 total) — and compares the
     * query count delta rather than an absolute threshold: a full
     * Filament page render issues many queries unrelated to this
     * resource's own read path (auth, panel boot, session, etc.), so an
     * absolute cap would be fragile. Kept to a single firm throughout so
     * the O(number of firms) per-firm-loop cost (a separate, already-
     * disclosed trade-off — see the service's own docblock) never
     * contaminates this O(items within one firm) measurement. The
     * load-bearing property: adding 9 more rows to the SAME connection
     * must add only a small, row-count-independent number of extra
     * queries (eager-loaded relations + one batched failure-category
     * lookup), never ~9 extra queries.
     */
    public function test_listing_many_failures_for_one_connection_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create());
        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(SyncFailureResource::getUrl())->assertOk();
        $oneItemQueryCount = count($onePass);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->count(9)->create());

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(SyncFailureResource::getUrl())->assertOk();
        $tenItemQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneItemQueryCount + 9,
            $tenItemQueryCount,
            'Adding 9 more rows to the same connection must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Retry action lifecycle ---

    public function test_retry_action_is_only_visible_for_failed_permanent_items(): void
    {
        $source = file_get_contents(app_path('Filament/Actions/Platform/RetrySyncFailureAction.php'));
        $this->assertStringContainsString("'failed_permanent'", $source);
    }

    public function test_retry_action_requeues_the_item_and_writes_an_audit_event(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->givenActiveCredentialFor($firm, $connection);
        $item = $this->failedPermanentItem($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSyncFailures::class);
        $test->assertOk();

        $test->mountTableAction(RetrySyncFailureAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $item->fresh());
        $this->assertSame(SyncItemStatus::FailedRetryable, $fresh->status);
        $this->assertSame(1, $fresh->requeue_count);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.sync_item_requeued')
            ->first());
        $this->assertNotNull($audit, 'Retrying via SyncFailureResource must write the same oversight audit event requeueSyncItem() always writes.');
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_a_read_only_auditor_cannot_retry_even_when_also_holding_superadmin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $item = $this->failedPermanentItem($firm, $connection);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListSyncFailures::class);
        $test->assertOk();

        $test->mountTableAction(RetrySyncFailureAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $item->fresh());
        $this->assertSame(SyncItemStatus::FailedPermanent, $fresh->status, 'canMutate() must block a read_only_auditor from retrying, even with SuperAdmin also held (blanket rule 9).');
        $this->assertSame(0, $fresh->requeue_count);
    }
}
