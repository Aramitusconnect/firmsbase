<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;
use App\Services\AiPolicySettingService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AiPolicySettingServiceTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Configuration" category). The FIRST test file
 * `AiPolicySetting` has ever had (per this phase's own architecture
 * investigation: "No test files exist for AiPolicySetting... only the
 * model, its factory, and the creating migration existed before this
 * class").
 */
final class AiPolicySettingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiPolicySettingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiPolicySettingService(new PlatformAdminAuditEventRecorder);
    }

    public function test_get_returns_null_when_no_row_exists(): void
    {
        $this->assertNull($this->service->get('nonexistent_key'));
    }

    public function test_set_creates_a_new_row_when_none_exists(): void
    {
        $setting = $this->service->set('firm_owned_ai_mode_globally_permitted', ['enabled' => true]);

        $this->assertSame('firm_owned_ai_mode_globally_permitted', $setting->key);
        $this->assertSame(['enabled' => true], $setting->value_json);
        $this->assertSame(['enabled' => true], $this->service->get('firm_owned_ai_mode_globally_permitted'));
    }

    public function test_set_upserts_an_existing_row_rather_than_creating_a_duplicate(): void
    {
        $this->service->set('some_key', ['enabled' => true]);
        $this->service->set('some_key', ['enabled' => false, 'reason' => 'temporarily disabled']);

        $this->assertSame(1, AiPolicySetting::query()->where('key', 'some_key')->count());
        $this->assertSame(['enabled' => false, 'reason' => 'temporarily disabled'], $this->service->get('some_key'));
    }

    public function test_set_rejects_an_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->set('   ', ['enabled' => true]);
    }

    public function test_set_without_an_actor_leaves_updated_by_null_and_writes_no_audit_event(): void
    {
        $setting = $this->service->set('no_actor_key', ['enabled' => true]);

        $this->assertNull(
            $setting->updated_by,
            'updated_by is a real FK to `users`, not `platform_admins` — must stay null for an admin-initiated write.'
        );

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'ai_policy_setting_created')
                ->count()
        );
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_set_with_an_actor_writes_a_platform_level_audit_event_on_create(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $setting = $this->service->set('with_actor_key', ['enabled' => true], $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'ai_policy_setting_created')
                ->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id, 'AiPolicySetting is Global/no-RLS — the audit row must be platform-level (null firm_id).');
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('ai_policy_settings', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($setting->id, $metadata['ai_policy_setting_id']);
        $this->assertSame('with_actor_key', $metadata['key']);
    }

    public function test_set_with_an_actor_writes_an_updated_event_type_on_a_second_write(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->service->set('update_key', ['enabled' => true], $admin);
        $this->service->set('update_key', ['enabled' => false], $admin);

        $rows = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'ai_policy_setting_updated')
                ->get()
        );

        $this->assertCount(1, $rows);
    }
}
