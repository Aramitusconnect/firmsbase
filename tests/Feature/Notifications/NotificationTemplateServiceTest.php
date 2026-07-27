<?php

namespace Tests\Feature\Notifications;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\NotificationTemplateService;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationTemplateService;
    }

    public function test_resolve_returns_the_global_default_when_no_firm_override_exists(): void
    {
        $firm = Firm::factory()->create();
        $global = $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Please upload your document.');

        $resolved = $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email);

        $this->assertTrue($resolved->is($global));
    }

    public function test_resolve_prefers_a_firm_override_over_the_global_default(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body.');
        $override = $this->service->createFirmOverride($firm, 'document_reminder', ConsentChannel::Email, 'Firm-specific body.');

        $resolved = $this->runWithFirmContext($firm, fn () => $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email));

        $this->assertTrue($resolved->is($override));
    }

    public function test_resolve_ignores_an_archived_template(): void
    {
        $firm = Firm::factory()->create();
        $global = $this->service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body.');
        $this->service->archive($global);

        $resolved = $this->service->resolve($firm, 'document_reminder', ConsentChannel::Email);

        $this->assertNull($resolved);
    }

    public function test_only_one_global_default_may_exist_per_key_channel_language(): void
    {
        NotificationTemplate::factory()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);

        $this->expectException(QueryException::class);

        NotificationTemplate::factory()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);
    }

    public function test_only_one_firm_override_may_exist_per_firm_key_channel_language(): void
    {
        $firm = Firm::factory()->create();
        NotificationTemplate::factory()->forFirm($firm)->create([
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);

        $this->expectException(QueryException::class);

        NotificationTemplate::factory()->forFirm($firm)->create([
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
        ]);
    }

    public function test_is_global_default_is_true_only_when_firm_id_is_null(): void
    {
        $firm = Firm::factory()->create();
        $global = NotificationTemplate::factory()->create(['firm_id' => null]);
        $override = NotificationTemplate::factory()->forFirm($firm)->create();

        $this->assertTrue($global->isGlobalDefault());
        $this->assertFalse($override->isGlobalDefault());
    }

    // ------------------------------------------------------------
    // Phase 4 FirmsVault Admin Control Center ("Configuration"
    // category) additions — actor + audit plumbing on
    // createGlobalDefault()/createFirmOverride()/archive().
    // ------------------------------------------------------------

    public function test_create_global_default_without_an_actor_writes_no_audit_event(): void
    {
        $this->service->createGlobalDefault('no_actor_key', ConsentChannel::Email, 'Body.');

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'notification_template_global_default_created')
                ->count()
        );
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_create_global_default_with_an_actor_writes_a_platform_level_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $template = $this->service->createGlobalDefault(
            'with_actor_key',
            ConsentChannel::Email,
            'Body.',
            actor: $admin,
        );

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'notification_template_global_default_created')
                ->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id, 'A global default template has no firm — the audit row must be platform-level (null firm_id), not firm-scoped.');
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($template->id, $metadata['notification_template_id']);
        $this->assertSame('with_actor_key', $metadata['key']);
    }

    public function test_create_firm_override_with_an_actor_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $template = $this->service->createFirmOverride(
            $firm,
            'firm_override_key',
            ConsentChannel::Email,
            'Firm body.',
            actor: $admin,
        );

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'notification_template_firm_override_created')
            ->first());

        $this->assertNotNull($audit, 'A firm override template carries a real firm_id — the audit row must be firm-scoped, never platform-level.');
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($template->id, $audit->metadata['notification_template_id']);
    }

    public function test_archive_with_an_actor_writes_a_platform_level_audit_event_for_a_global_template(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $global = $this->service->createGlobalDefault('archive_global_key', ConsentChannel::Email, 'Body.');

        $archived = $this->service->archive($global, $admin);

        $this->assertSame(NotificationTemplateStatus::Archived, $archived->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'notification_template_archived')
                ->first()
        );
        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame($admin->id, $row->actor_id);
    }

    public function test_archive_with_an_actor_writes_a_firm_scoped_audit_event_for_a_firm_override(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $override = $this->service->createFirmOverride($firm, 'archive_override_key', ConsentChannel::Email, 'Firm body.');

        $archived = $this->service->archive($override, $admin);

        $this->assertSame(NotificationTemplateStatus::Archived, $archived->status);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'notification_template_archived')
            ->first());
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
    }
}
