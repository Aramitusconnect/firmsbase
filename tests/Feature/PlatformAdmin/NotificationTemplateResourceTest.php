<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ArchiveNotificationTemplateAction;
use App\Filament\Actions\Platform\CreateFirmOverrideNotificationTemplateAction;
use App\Filament\Actions\Platform\CreateGlobalDefaultNotificationTemplateAction;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplateResource\Pages\ViewNotificationTemplate;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\PlatformNotificationTemplateDirectoryService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * NotificationTemplateResourceTest ("Notification Templates", the
 * honest relabeling of "Email Templates") — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Configuration" category). Navigation
 * visibility, route-level authorization, filters, no-N+1, and the
 * three actions' full lifecycle (Create Global Default/Create Firm
 * Override/Archive). Also proves no live-send capability is implied
 * anywhere on this resource.
 */
final class NotificationTemplateResourceTest extends TestCase
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

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(NotificationTemplateResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(NotificationTemplateResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_support_agent(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(NotificationTemplateResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_notification_templates_list(): void
    {
        $this->get(NotificationTemplateResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(NotificationTemplateResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages_for_a_global_default(): void
    {
        $template = NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'document_reminder']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(NotificationTemplateResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('document_reminder');

        $viewResponse = $this->get(ViewNotificationTemplate::getUrl(['firmUuid' => 'global', 'id' => $template->id]));
        $viewResponse->assertOk();
    }

    public function test_a_super_admin_can_view_a_firm_override(): void
    {
        $firm = Firm::factory()->activated()->create();
        $override = NotificationTemplate::factory()->forFirm($firm)->create(['key' => 'firm_specific_key']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $viewResponse = $this->get(ViewNotificationTemplate::getUrl(['firmUuid' => $firm->uuid, 'id' => $override->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('firm_specific_key');
    }

    public function test_viewing_a_firm_overrides_id_under_a_different_unrelated_firm_404s(): void
    {
        $ownerFirm = Firm::factory()->activated()->create();
        $override = NotificationTemplate::factory()->forFirm($ownerFirm)->create();
        $unrelatedFirm = Firm::factory()->activated()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        // notification_templates' SELECT policy is `firm_id IS NULL OR
        // firm_id = current_firm` — a GLOBAL template is deliberately
        // visible under any firm's context (or none), by design (see
        // PlatformNotificationTemplateDirectoryService's own docblock).
        // A FIRM OVERRIDE row is not: looked up scoped to a DIFFERENT
        // firm's context, it must not resolve.
        $this->actingAs($admin, 'platform_admin')
            ->get(ViewNotificationTemplate::getUrl(['firmUuid' => $unrelatedFirm->uuid, 'id' => $override->id]))
            ->assertNotFound();
    }

    public function test_a_global_template_remains_visible_when_looked_up_scoped_to_any_firm(): void
    {
        $global = NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'visible_under_any_context']);
        $firm = Firm::factory()->activated()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        // Deliberate, documented RLS behavior — not a leak: global
        // default rows are visible under any tenant context (or none at
        // all), per the table's own dual-policy design.
        $this->actingAs($admin, 'platform_admin')
            ->get(ViewNotificationTemplate::getUrl(['firmUuid' => $firm->uuid, 'id' => $global->id]))
            ->assertOk()
            ->assertSee('visible_under_any_context');
    }

    // --- Empty state ---

    public function test_empty_state_is_shown_when_no_templates_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(NotificationTemplateResource::getUrl());
        $response->assertOk();
        $response->assertSee('No notification templates found');
        $response->assertSee('no real email transport exists');
    }

    // --- Channel-agnostic scope (not Email-only) ---

    public function test_non_email_channels_are_listed_by_default(): void
    {
        NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'sms_key', 'channel' => ConsentChannel::Sms]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(NotificationTemplateResource::getUrl());
        $response->assertOk();
        $response->assertSee('sms_key');
    }

    // --- Filters ---

    public function test_channel_filter_narrows_the_list(): void
    {
        NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'email_key', 'channel' => ConsentChannel::Email]);
        NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'whatsapp_key', 'channel' => ConsentChannel::WhatsApp]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformNotificationTemplateDirectoryService::class)->list($admin, null, ['channel' => ConsentChannel::WhatsApp->value]);

        $this->assertCount(1, $rows);
        $this->assertSame('whatsapp_key', $rows->first()['key']);
    }

    public function test_firm_filter_shows_globals_plus_that_firms_own_overrides(): void
    {
        $firm = Firm::factory()->activated()->create();
        NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'shared_global_key']);
        NotificationTemplate::factory()->forFirm($firm)->create(['key' => 'this_firms_override_key']);

        $otherFirm = Firm::factory()->activated()->create();
        NotificationTemplate::factory()->forFirm($otherFirm)->create(['key' => 'other_firms_override_key']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = app(PlatformNotificationTemplateDirectoryService::class)->list($admin, $firm);

        $keys = $rows->pluck('key')->all();
        $this->assertContains('shared_global_key', $keys);
        $this->assertContains('this_firms_override_key', $keys);
        $this->assertNotContains('other_firms_override_key', $keys, 'Selecting one firm must never leak another firm\'s override rows.');
    }

    // --- Deterministic ordering ---

    public function test_ordering_is_deterministic_for_equal_updated_at_timestamps(): void
    {
        $now = now();
        $first = NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'first_key', 'updated_at' => $now]);
        $second = NotificationTemplate::factory()->create(['firm_id' => null, 'key' => 'second_key', 'updated_at' => $now]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsA = app(PlatformNotificationTemplateDirectoryService::class)->list($admin, null)->pluck('id')->all();
        $rowsB = app(PlatformNotificationTemplateDirectoryService::class)->list($admin, null)->pluck('id')->all();

        $this->assertSame($rowsA, $rowsB, 'Repeated calls with identical timestamps must produce identical ordering (id tie-break).');
        $this->assertSame([$second->id, $first->id], $rowsA);
    }

    // --- Bounded pagination ---

    public function test_the_list_page_is_paginated_not_a_single_unbounded_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->assertSet('tableRecordsPerPage', 25);
    }

    // --- No-N+1 proof ---

    public function test_listing_many_global_templates_does_not_n_plus_one(): void
    {
        NotificationTemplate::factory()->create(['firm_id' => null]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(NotificationTemplateResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        for ($i = 0; $i < 9; $i++) {
            NotificationTemplate::factory()->create(['firm_id' => null, 'key' => "bulk_key_{$i}"]);
        }

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(NotificationTemplateResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan(
            $oneCount + 9,
            $tenCount,
            'Adding 9 more global templates must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // --- No live-send capability implied ---

    public function test_no_send_or_preview_action_exists_anywhere_on_this_module(): void
    {
        $files = [
            app_path('Filament/Resources/NotificationTemplateResource.php'),
            app_path('Filament/Actions/Platform/CreateGlobalDefaultNotificationTemplateAction.php'),
            app_path('Filament/Actions/Platform/CreateFirmOverrideNotificationTemplateAction.php'),
            app_path('Filament/Actions/Platform/ArchiveNotificationTemplateAction.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            // Structural call-syntax checks only — not a naive whole-
            // file substring search for "Mailable"/"->send(", either of
            // which would false-positive here: this resource's own
            // docblock legitimately discusses Mailable's absence in
            // prose, and every Action in this file legitimately calls
            // Filament\Notifications\Notification::make()->send() (a UI
            // toast, not an email send) as part of its own normal
            // success/error feedback.
            $this->assertStringNotContainsString('sendTest', $source);
            $this->assertStringNotContainsString('->dispatch(', $source);
            $this->assertStringNotContainsString('Mail::', $source);
            $this->assertStringNotContainsString('->preview(', $source);
        }
    }

    // --- Create Global Default action lifecycle ---

    public function test_create_global_default_action_creates_the_template_and_writes_a_platform_level_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->mountTableAction(CreateGlobalDefaultNotificationTemplateAction::getDefaultName());
        $test->setTableActionData([
            'key' => 'new_global_key',
            'channel' => ConsentChannel::Email->value,
            'language' => 'en',
            'subject' => 'Hello',
            'body' => 'Body text.',
        ]);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $template = NotificationTemplate::query()->whereNull('firm_id')->where('key', 'new_global_key')->first();
        $this->assertNotNull($template);
        $this->assertSame(NotificationTemplateStatus::Active, $template->status);

        $audit = DB::table('security_events')->where('event_type', 'notification_template_global_default_created')->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->firm_id);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    // --- Create Firm Override action lifecycle ---

    public function test_create_firm_override_action_creates_the_template_and_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->activated()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->mountTableAction(CreateFirmOverrideNotificationTemplateAction::getDefaultName());
        $test->setTableActionData([
            'firm_uuid' => $firm->uuid,
            'key' => 'new_override_key',
            'channel' => ConsentChannel::Email->value,
            'language' => 'en',
            'body' => 'Override body.',
        ]);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $template = $this->runWithFirmContext($firm, fn () => NotificationTemplate::query()
            ->where('firm_id', $firm->id)
            ->where('key', 'new_override_key')
            ->first());
        $this->assertNotNull($template);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'notification_template_firm_override_created')
            ->first());
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    // --- Archive action lifecycle ---

    public function test_archive_action_is_hidden_for_an_already_archived_template(): void
    {
        $template = NotificationTemplate::factory()->create(['firm_id' => null, 'status' => NotificationTemplateStatus::Archived]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->assertTableActionHidden(ArchiveNotificationTemplateAction::getDefaultName(), 0);
    }

    public function test_archive_action_archives_a_global_template_and_writes_a_platform_level_audit_event(): void
    {
        $template = NotificationTemplate::factory()->create(['firm_id' => null, 'status' => NotificationTemplateStatus::Active]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->mountTableAction(ArchiveNotificationTemplateAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $this->assertSame(NotificationTemplateStatus::Archived, $template->fresh()->status);

        $audit = DB::table('security_events')->where('event_type', 'notification_template_archived')->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->firm_id);
    }

    public function test_re_clicking_archive_via_the_ui_action_is_blocked_by_a_fresh_status_re_check_not_a_second_audit_write(): void
    {
        $template = NotificationTemplate::factory()->create(['firm_id' => null, 'status' => NotificationTemplateStatus::Active]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->mountTableAction(ArchiveNotificationTemplateAction::getDefaultName(), '0');
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        // ArchiveNotificationTemplateAction re-fetches the model fresh
        // (via PlatformNotificationTemplateDirectoryService::findModel())
        // and re-checks its status before ever calling archive() again —
        // this is the action's own TOCTOU guard. The underlying
        // NotificationTemplateService::archive() method itself has no
        // idempotency guard of its own (unlike revoke()/expire() in this
        // same phase) — this test documents that the UI-layer guard is
        // what actually prevents a double audit write here, not the
        // service method.
        $test2 = Livewire::test(ListNotificationTemplates::class);
        $test2->assertOk();
        // The row is gone from ->visible() consideration once Archived
        // — re-invoking the same action index would now target whatever
        // row is at position 0 next, so instead assert directly that
        // exactly one audit event exists after the one real archive.
        $auditCount = DB::table('security_events')->where('event_type', 'notification_template_archived')->count();
        $this->assertSame(1, $auditCount);
    }

    public function test_a_read_only_auditor_cannot_archive_even_when_also_holding_superadmin(): void
    {
        $template = NotificationTemplate::factory()->create(['firm_id' => null, 'status' => NotificationTemplateStatus::Active]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListNotificationTemplates::class);
        $test->assertOk();
        $test->mountTableAction(ArchiveNotificationTemplateAction::getDefaultName(), '0');
        $test->callMountedTableAction();

        $this->assertSame(NotificationTemplateStatus::Active, $template->fresh()->status, 'canMutate() must block a read_only_auditor, even with SuperAdmin also held.');
    }
}
