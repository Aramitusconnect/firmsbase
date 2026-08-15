<?php

namespace Tests\Feature\Operations;

use App\Enums\PlatformRoleCode;
use App\Enums\StatusPageEventStatus;
use App\Filament\Pages\PlatformStatusPageEventsPage;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformRoleService;
use App\Services\StatusPagePublicationCapabilityService;
use App\Services\StatusPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Operations Control Plane — proves the console never presents an
 * internal status_page_events row as a published public
 * communication.
 *
 * This matters more than it looks. During an incident the difference
 * between "customers have been told" and "customers have not been
 * told" determines whether anyone goes and tells them. A green
 * Published badge over a table nobody outside this panel can read is
 * the kind of defect that only reveals itself after the outage.
 */
class StatusPublicationTruthTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_no_public_status_endpoint_exists_in_this_codebase(): void
    {
        $capability = app(StatusPagePublicationCapabilityService::class);

        $this->assertFalse(
            $capability->hasPublicPublicationBackend(),
            'A public status route now exists — the console wording and this mission\'s reported '.
            'PUBLIC_STATUS_PUBLICATION_BACKEND finding must both be revisited.',
        );
        $this->assertNull($capability->publicStatusRouteUri());
    }

    public function test_the_capability_is_derived_from_the_route_table_not_hardcoded(): void
    {
        Route::get('/status', fn (): string => 'ok');
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(
            app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend(),
            'adding a real public status route must flip the capability, or the disclosure will go stale',
        );
    }

    public function test_an_authenticated_status_route_does_not_count_as_public(): void
    {
        Route::middleware('auth:platform_admin')->get('/status', fn (): string => 'ok');
        Route::getRoutes()->refreshNameLookups();

        $this->assertFalse(
            app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend(),
            'a status page behind authentication is not a public status page',
        );
    }

    public function test_the_disclosure_states_that_customers_are_not_notified(): void
    {
        $disclosure = app(StatusPagePublicationCapabilityService::class)->disclosure();

        $this->assertStringContainsString('NO PUBLIC STATUS PAGE EXISTS', $disclosure);
        $this->assertStringContainsString('Customers are NOT informed', $disclosure);
    }

    public function test_the_page_warns_that_records_are_not_public(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformStatusPageEventsPage::getUrl());

        $response->assertOk();
        $response->assertSee('These Updates Are NOT Published Publicly');
        $response->assertSee('Customers are NOT informed', false);
    }

    public function test_a_published_record_is_not_labelled_as_public_while_no_endpoint_exists(): void
    {
        app(StatusPageService::class)->publish(
            'investigating',
            'API',
            'We are investigating elevated error rates.',
            now(),
        );

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformStatusPageEventsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Recorded (Not Public)');
    }

    public function test_the_underlying_publish_call_still_records_the_canonical_state(): void
    {
        $event = app(StatusPageService::class)->publish(
            'investigating',
            'API',
            'We are investigating elevated error rates.',
            now(),
        );

        // The correction is presentational and disclosure-level only:
        // the canonical domain state machine is untouched, so nothing
        // downstream of StatusPageService changes meaning.
        $this->assertSame(StatusPageEventStatus::Published, $event->status);
        $this->assertDatabaseHas('status_page_events', [
            'id' => $event->id,
            'status' => StatusPageEventStatus::Published->value,
        ]);
    }

    public function test_internal_incident_detail_is_never_copied_into_a_public_message(): void
    {
        $incidentCorrelationId = (string) Str::uuid();

        $event = app(StatusPageService::class)->publish(
            'investigating',
            'API',
            'Some customers may see errors. We are working on it.',
            now(),
            $incidentCorrelationId,
        );

        $stored = StatusPageEvent::query()->findOrFail($event->id);

        // The public message is only ever what the author typed —
        // there is no code path anywhere that copies an incident
        // description, root cause, or resolution into it.
        $this->assertSame('Some customers may see errors. We are working on it.', $stored->public_message);
        $this->assertSame($incidentCorrelationId, $stored->incident_correlation_id);
    }
}
