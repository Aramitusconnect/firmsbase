<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformConfigurationOverviewPage;
use App\Models\AiPolicySetting;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\AiModeResolutionService;
use App\Services\AiPolicySettingService;
use App\Services\Configuration\ConfigurationOverviewReadService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Configuration Overview's single most important property is that
 * it never fabricates reassurance. A metric it cannot measure must say
 * so; a metric it did measure may report zero.
 */
class ConfigurationOverviewTest extends TestCase
{
    use RefreshDatabase;

    private ConfigurationOverviewReadService $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = app(ConfigurationOverviewReadService::class);
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    public function test_the_page_renders_for_an_authorized_admin(): void
    {
        $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(PlatformConfigurationOverviewPage::getUrl())
            ->assertOk()
            ->assertSee('Configuration Overview');
    }

    public function test_the_page_is_not_reachable_without_platform_admin_authentication(): void
    {
        $this->get(PlatformConfigurationOverviewPage::getUrl())->assertRedirect();
    }

    /**
     * The core anti-fabrication guarantee.
     */
    public function test_tenant_scoped_entitlement_metrics_are_reported_as_unavailable_never_zero(): void
    {
        foreach ($this->reader->entitlementMetrics() as $metric) {
            $this->assertFalse($metric['available'], "{$metric['label']} must not claim to be measurable here");
            $this->assertNull($metric['value'], "{$metric['label']} must not report a number");
            $this->assertNotEmpty($metric['note']);
        }
    }

    public function test_the_rendered_page_shows_not_available_rather_than_zero_for_entitlements(): void
    {
        $this->actingAs($this->superAdmin(), 'platform_admin')
            ->get(PlatformConfigurationOverviewPage::getUrl())
            ->assertOk()
            ->assertSee('Firms with overrides')
            ->assertSee('Not available');
    }

    public function test_practice_area_metrics_are_genuinely_measured(): void
    {
        $before = collect($this->reader->practiceAreaMetrics())->firstWhere('label', 'Active practice areas')['value'];

        PracticeArea::factory()->create(['code' => 'zzz_overview_active', 'is_active' => true]);

        $after = collect($this->reader->practiceAreaMetrics())->firstWhere('label', 'Active practice areas')['value'];

        $this->assertSame($before + 1, $after);
    }

    public function test_a_measured_zero_is_still_marked_available(): void
    {
        $metric = collect($this->reader->practiceAreaMetrics())->firstWhere('label', 'Inactive practice areas');

        // Whatever the count is, it was genuinely measured — mission
        // section 24's distinction between 0 and unavailable.
        $this->assertTrue($metric['available']);
        $this->assertIsInt($metric['value']);
    }

    public function test_the_seeded_catalogs_real_duplicates_surface_in_requires_attention(): void
    {
        $items = collect($this->reader->requiresAttention());

        $this->assertNotEmpty(
            $items->filter(fn (array $i): bool => str_contains($i['title'], 'suspected duplicate practice area')),
            'the seeded catalog contains real separator-variant duplicates, which must be surfaced',
        );
    }

    public function test_an_invalid_global_template_is_surfaced_as_action_required(): void
    {
        // Written directly, bypassing the service, to simulate content
        // stored before the content policy existed.
        NotificationTemplate::query()->create([
            'firm_id' => null,
            'key' => 'zzz_legacy_unsafe',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
            'status' => NotificationTemplateStatus::Active,
            'subject' => 'Hi',
            'body' => '@if ($x) Hello @endif',
        ]);

        $items = collect($this->reader->requiresAttention());
        $match = $items->firstWhere('severity', 'danger');

        $this->assertNotNull($match);
        $this->assertNotEmpty(
            $items->filter(fn (array $i): bool => str_contains($i['title'], 'fail content validation')),
        );
    }

    public function test_an_engaged_kill_switch_is_surfaced_as_action_required(): void
    {
        app(AiPolicySettingService::class)->set(
            AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY,
            false,
            allowGovernedKey: true,
        );

        $items = collect($this->reader->requiresAttention());

        $this->assertNotEmpty(
            $items->filter(fn (array $i): bool => str_contains($i['title'], 'kill switch is ENGAGED')),
        );
    }

    public function test_an_invalid_ai_policy_value_is_surfaced(): void
    {
        // Written directly to the model, simulating a value stored
        // before type validation existed.
        AiPolicySetting::query()->create([
            'key' => AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY,
            'value_json' => 'not-a-boolean',
        ]);

        $items = collect($this->reader->requiresAttention());

        $this->assertNotEmpty(
            $items->filter(fn (array $i): bool => str_contains($i['title'], 'has an invalid value')),
        );
    }

    public function test_ai_metrics_report_the_absent_firm_override_layer_honestly(): void
    {
        $metric = collect($this->reader->aiPolicyMetrics())->firstWhere('label', 'Firm-level AI policy overrides');

        // This zero is real and explained — the column does not exist,
        // so the layer genuinely cannot hold anything.
        $this->assertSame(0, $metric['value']);
        $this->assertStringContainsString('no firm_id column', $metric['note']);
    }

    public function test_notification_metrics_disclose_versioning_and_required_catalog_as_absent(): void
    {
        $metrics = collect($this->reader->notificationTemplateMetrics());

        foreach (['Missing required templates', 'Unpublished changes / version history'] as $label) {
            $metric = $metrics->firstWhere('label', $label);

            $this->assertFalse($metric['available'], "{$label} must not be fabricated");
            $this->assertStringContainsString('Not implemented', $metric['note']);
        }
    }

    public function test_capability_gaps_name_every_absent_capability_with_a_reason(): void
    {
        $gaps = collect($this->reader->capabilityGaps());

        $this->assertNotEmpty($gaps->firstWhere('capability', 'Practice area alias resolution'));
        $this->assertNotEmpty($gaps->firstWhere('capability', 'Notification template versioning'));
        $this->assertNotEmpty($gaps->firstWhere('capability', 'Practice area hierarchy'));

        foreach ($gaps as $gap) {
            $this->assertNotEmpty($gap['reason'], "{$gap['capability']} must explain why it is unavailable");
        }
    }

    public function test_the_overview_performs_no_per_firm_scan(): void
    {
        Firm::factory()->count(5)->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->reader->practiceAreaMetrics();
        $this->reader->entitlementMetrics();
        $this->reader->aiPolicyMetrics();
        $this->reader->notificationTemplateMetrics();

        // A per-firm loop would show up as repeated tenant-context
        // statements. The overview must never take that shape.
        $contextStatements = array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'set_config') || str_contains($sql, 'current_firm_id'),
        );

        $this->assertCount(0, $contextStatements, 'the overview must not open per-firm tenant contexts');
    }
}
