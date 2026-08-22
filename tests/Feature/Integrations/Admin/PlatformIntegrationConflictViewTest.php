<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationConflictViewTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §10 items 3 and 5). Proves conflict metadata
 * renders correctly, `resolution_note` is null by default and gated
 * strictly behind an active SupportAccessSession for the EXACT firm —
 * including for a ceiling-role admin with NO active session, who must
 * still see null — and that `local_value`/`external_value` are NEVER
 * present anywhere, even when planted with real marker values.
 */
final class PlatformIntegrationConflictViewTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_VALUE_MARKER = 'SECRET-MARKER-conflict-local-4b7e9a2f1d8c3650';

    private const EXTERNAL_VALUE_MARKER = 'SECRET-MARKER-conflict-external-7c2a5f8e1b9d4036';

    private const RESOLUTION_NOTE = 'This was resolved by matching the provider record manually.';

    public function test_resolution_note_is_null_without_an_active_support_access_session_even_for_a_ceiling_role_admin(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'resolution_note' => self::RESOLUTION_NOTE,
        ]));

        // SuperAdmin — a ceiling role, unconditionally trusted for
        // assertCanAccessFirm() — but deliberately holds NO active
        // support-access session.
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $conflicts = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);

        $this->assertCount(1, $conflicts);
        $this->assertNull($conflicts->first()['resolution_note'], 'A ceiling-role admin with no active support-access session must still see resolution_note as null.');
    }

    public function test_resolution_note_is_visible_with_an_active_support_access_session_for_the_exact_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'resolution_note' => self::RESOLUTION_NOTE,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $firm);

        $conflicts = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);

        $this->assertSame(self::RESOLUTION_NOTE, $conflicts->first()['resolution_note']);
    }

    public function test_resolution_note_remains_null_when_the_active_session_is_for_a_different_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'resolution_note' => self::RESOLUTION_NOTE,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $otherFirm);

        $conflicts = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);

        $this->assertNull($conflicts->first()['resolution_note']);
    }

    public function test_local_value_and_external_value_never_appear_anywhere_in_the_conflicts_output(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'local_value' => ['confidential' => self::LOCAL_VALUE_MARKER],
            'external_value' => ['confidential' => self::EXTERNAL_VALUE_MARKER],
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $firm);

        $conflicts = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);

        $encoded = json_encode($conflicts->all());
        $this->assertStringNotContainsString(self::LOCAL_VALUE_MARKER, $encoded);
        $this->assertStringNotContainsString(self::EXTERNAL_VALUE_MARKER, $encoded);

        foreach ($conflicts as $conflict) {
            $this->assertArrayNotHasKey('local_value', $conflict);
            $this->assertArrayNotHasKey('external_value', $conflict);
        }
    }

    public function test_the_detail_page_never_renders_local_or_external_value_markers_even_with_an_active_session(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'local_value' => ['confidential' => self::LOCAL_VALUE_MARKER],
            'external_value' => ['confidential' => self::EXTERNAL_VALUE_MARKER],
            'resolution_note' => self::RESOLUTION_NOTE,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $firm);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertDontSee(self::LOCAL_VALUE_MARKER);
        $test->assertDontSee(self::EXTERNAL_VALUE_MARKER);
        // The resolution note itself IS expected to render, since a
        // real active session is held for this exact firm.
        $test->assertSee(self::RESOLUTION_NOTE);
    }

    public function test_conflict_metadata_fields_render_correctly(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create([
            'conflict_type' => 'field_value_mismatch',
            'resource_type' => 'contact',
            'status' => ConflictStatus::AwaitingReview->value,
            'requires_manual_review' => true,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $conflicts = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);

        $conflict = $conflicts->first();
        $this->assertSame('field_value_mismatch', $conflict['conflict_type']);
        $this->assertSame('contact', $conflict['resource_type']);
        $this->assertSame('awaiting_review', $conflict['status']);
        $this->assertTrue($conflict['requires_manual_review']);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): void
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        $this->runWithFirmContext($firm, fn () => SupportAccessSession::factory()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
            'platform_admin_id' => $admin->id,
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]));
    }
}
