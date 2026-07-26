<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\WebhookEventResource;
use App\Filament\Resources\WebhookEventResource\Pages\ViewWebhookEvent;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WebhookEventResourceTest — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Route-level authorization,
 * cross-firm listing, redaction of payload-shaped columns, no-N+1, and a
 * positive proof that this resource registers no mutating action of any
 * kind (per its own docblock: "List+View only, NO mutating action").
 */
final class WebhookEventResourceTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD_MARKER = 'SECRET-MARKER-webhook-payload-4e9d';

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

    // --- Navigation visibility ---
    // (see SyncFailureResourceTest's own docblock note on why canAccess()
    // is the real signal for a Resource, not shouldRegisterNavigation().)

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(WebhookEventResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(WebhookEventResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_webhook_events_list(): void
    {
        $this->get(WebhookEventResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(WebhookEventResource::getUrl())->assertForbidden();
    }

    public function test_a_billing_admin_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);

        $this->actingAs($admin, 'platform_admin')->get(WebhookEventResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Webhook Firm']);
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(WebhookEventResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Webhook Firm');

        $viewResponse = $this->get(ViewWebhookEvent::getUrl(['firmUuid' => $firm->uuid, 'id' => $event->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_an_event_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connection = $this->connection($firmA);
        $event = $this->runWithFirmContext($firmA, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewWebhookEvent::getUrl(['firmUuid' => $firmB->uuid, 'id' => $event->id]))
            ->assertNotFound();
    }

    // --- Redaction ---

    public function test_payload_data_never_appears_in_the_rendered_list_or_view_pages(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create([
            'payload_reference_json' => ['note' => self::PAYLOAD_MARKER],
            'payload_hash' => 'cafebabe',
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(WebhookEventResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertDontSee(self::PAYLOAD_MARKER);
        $listResponse->assertDontSee('cafebabe');

        $viewResponse = $this->get(ViewWebhookEvent::getUrl(['firmUuid' => $firm->uuid, 'id' => $event->id]));
        $viewResponse->assertOk();
        $viewResponse->assertDontSee(self::PAYLOAD_MARKER);
        $viewResponse->assertDontSee('cafebabe');
    }

    // --- Positive proof: no mutating action exists ---

    public function test_the_resource_registers_no_mutating_action_of_any_kind(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/WebhookEventResource.php'));

        $this->assertStringNotContainsString('requiresConfirmation', $source);
        $this->assertStringNotContainsString('requeue', strtolower($source));
        $this->assertStringNotContainsString('->action(', $source, 'No Filament ->action() callback (a mutating Action) may be registered anywhere in this class.');

        // Only the "view" navigation Action exists on recordActions().
        $this->assertStringContainsString("Action::make('view')", $source);
        $this->assertMatchesRegularExpression('/Action::make\(/', $source);
        $this->assertSame(1, substr_count($source, 'Action::make('), 'Exactly one Action (the read-only "view" link) may be registered — no mutating action.');
    }

    // --- No-N+1 proof ---

    public function test_listing_many_events_for_one_connection_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(WebhookEventResource::getUrl())->assertOk();
        $oneEventQueryCount = count($onePass);

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->count(9)->create());

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(WebhookEventResource::getUrl())->assertOk();
        $tenEventQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneEventQueryCount + 9,
            $tenEventQueryCount,
            'Adding 9 more rows to the same connection must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }
}
