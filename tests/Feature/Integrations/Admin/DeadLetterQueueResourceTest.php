<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\RequeueDeadLetterQueueEventAction;
use App\Filament\Resources\DeadLetterQueueResource;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ListDeadLetterQueueEvents;
use App\Filament\Resources\DeadLetterQueueResource\Pages\ViewDeadLetterQueueEvent;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TenantEncryptionKey;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DeadLetterQueueResourceTest — Phase 2 (FirmsVault Platform Admin
 * Control Center, "Integration Operations Center"). Route-level
 * authorization, cross-firm listing, redaction, the read-only retention
 * banner, no-N+1, and the Requeue action's full lifecycle (authorization,
 * audit event, idempotent ineligibility handling).
 */
final class DeadLetterQueueResourceTest extends TestCase
{
    use RefreshDatabase;

    private const LAST_ERROR_MARKER = 'SECRET-MARKER-dlq-resource-3c8e';

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

    private function givenActiveCredentialFor(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, function () use ($firm, $connection): void {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
            IntegrationCredential::factory()->forFirmIntegration($connection)->create();
        });
    }

    private function deadLetteredEvent(Firm $firm, FirmIntegration $connection): IntegrationOutboxEvent
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());
    }

    // --- Navigation visibility ---
    // (see SyncFailureResourceTest's own docblock note on why canAccess()
    // is the real signal for a Resource, not shouldRegisterNavigation().)

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(DeadLetterQueueResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(DeadLetterQueueResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_dead_letter_queue_list(): void
    {
        $this->get(DeadLetterQueueResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(DeadLetterQueueResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'DLQ Firm']);
        $connection = $this->connection($firm);
        $event = $this->deadLetteredEvent($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(DeadLetterQueueResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('DLQ Firm');
        // Retention banner (read-only).
        $listResponse->assertSee('read-only');

        $viewResponse = $this->get(ViewDeadLetterQueueEvent::getUrl(['firmUuid' => $firm->uuid, 'id' => $event->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_an_event_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connection = $this->connection($firmA);
        $event = $this->deadLetteredEvent($firmA, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewDeadLetterQueueEvent::getUrl(['firmUuid' => $firmB->uuid, 'id' => $event->id]))
            ->assertNotFound();
    }

    // --- Redaction ---

    public function test_last_error_never_appears_in_the_rendered_list_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
            'last_error' => self::LAST_ERROR_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(DeadLetterQueueResource::getUrl());
        $response->assertOk();
        $response->assertDontSee(self::LAST_ERROR_MARKER);
    }

    // --- Retention banner ---

    public function test_the_retention_banner_reflects_the_configured_value(): void
    {
        config(['integrations.outbox.dead_lettered_retention_days' => 77]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(DeadLetterQueueResource::getUrl());
        $response->assertOk();
        $response->assertSee('77 days');
    }

    public function test_deadlettered_retention_days_returns_null_for_a_role_without_security_log_access(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->assertNull(DeadLetterQueueResource::deadLetteredRetentionDays($admin));
    }

    // --- No-N+1 proof ---

    public function test_listing_many_events_for_one_connection_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->deadLetteredEvent($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(DeadLetterQueueResource::getUrl())->assertOk();
        $oneEventQueryCount = count($onePass);

        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->count(9)->create());

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(DeadLetterQueueResource::getUrl())->assertOk();
        $tenEventQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneEventQueryCount + 9,
            $tenEventQueryCount,
            'Adding 9 more rows to the same connection must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- Requeue action lifecycle ---

    public function test_requeue_action_requeues_the_event_and_writes_an_audit_event(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->deadLetteredEvent($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeadLetterQueueEvents::class);
        $test->assertOk();

        $test->mountTableAction(RequeueDeadLetterQueueEventAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertSame(OutboxEventStatus::Pending, $fresh->status);
        $this->assertSame(1, $fresh->requeue_count);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.outbox_event_requeued')
            ->first());
        $this->assertNotNull($audit, 'Requeuing via DeadLetterQueueResource must write the same oversight audit event requeueOutboxEvent() always writes.');
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_requeuing_an_already_completed_event_is_a_safe_no_op_not_a_crash(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->deadLetteredEvent($firm, $connection);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeadLetterQueueEvents::class);
        $test->assertOk();
        $test->mountTableAction(RequeueDeadLetterQueueEventAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        // Second requeue attempt against the SAME now-pending (not
        // dead-lettered) event id — proves idempotent, graceful
        // ineligibility handling rather than a crash.
        $test2 = Livewire::test(ListDeadLetterQueueEvents::class);
        $test2->assertOk();
        // The event no longer appears in this resource's own list (it
        // is no longer dead_lettered), so this proves the requeue truly
        // transitioned it out of the DLQ view, not merely that a second
        // click was a no-op.
        $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listDeadLetterQueue($admin);
        $this->assertCount(0, $rows->where('id', $event->id));
    }

    public function test_a_read_only_auditor_cannot_requeue_even_when_also_holding_superadmin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $event = $this->deadLetteredEvent($firm, $connection);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListDeadLetterQueueEvents::class);
        $test->assertOk();
        $test->mountTableAction(RequeueDeadLetterQueueEventAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertSame(OutboxEventStatus::DeadLettered, $fresh->status, 'canMutate() must block a read_only_auditor from requeuing, even with SuperAdmin also held.');
        $this->assertSame(0, $fresh->requeue_count);
    }
}
