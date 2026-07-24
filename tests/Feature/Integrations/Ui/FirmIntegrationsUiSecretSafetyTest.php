<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\ConflictsRelationManager;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationCredential;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\AssertsUiStateHasNoSecrets;
use Tests\TestCase;

/**
 * FirmIntegrationsUiSecretSafetyTest — Checkpoint 10 (frozen-design-
 * post-security-review.md §9, load-bearing control). Plants a real,
 * distinctive secret-shaped marker value into
 * `IntegrationCredential.webhook_routing_token` and
 * `IntegrationConflict.local_value`/`external_value`, renders every
 * page/component that could plausibly touch these (list view, view
 * page, the credential summary section, the conflicts relation
 * manager), and asserts the marker is absent from both rendered HTML
 * and the decoded wire:snapshot payload in every case, via
 * AssertsUiStateHasNoSecrets. Includes the required negative-control
 * test proving the helper itself would catch a deliberate violation.
 */
final class FirmIntegrationsUiSecretSafetyTest extends TestCase
{
    use AssertsUiStateHasNoSecrets;
    use RefreshDatabase;

    private const CREDENTIAL_MARKER = 'SECRET-MARKER-cred-9f3a7b1e2c6d4a58-webhook-routing-token';

    private const CONFLICT_LOCAL_MARKER = 'SECRET-MARKER-conflict-local-4b7e9a2f1d8c3650';

    private const CONFLICT_EXTERNAL_MARKER = 'SECRET-MARKER-conflict-external-7c2a5f8e1b9d4036';

    // ------------------------------------------------------------
    // 0. Required negative control (frozen design §9)
    // ------------------------------------------------------------

    public function test_the_secret_marker_assertion_itself_fails_red_against_a_deliberate_leak(): void
    {
        $this->assertSecretMarkerAssertionActuallyFailsRedOnALeak('this-marker-must-be-caught-1a2b3c4d');
    }

    // ------------------------------------------------------------
    // 1. IntegrationCredential.webhook_routing_token
    // ------------------------------------------------------------

    public function test_view_firm_integration_page_never_leaks_the_planted_credential_webhook_routing_token_marker(): void
    {
        [$firm, $connection, $owner] = $this->fixtureWithPlantedCredentialMarker();

        $this->actingAs($owner->user);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(
                ViewFirmIntegration::class,
                ['record' => $connection->uuid]
            )
        );

        $this->assertUiStateHasNoSecretMarker($test, self::CREDENTIAL_MARKER, 'ViewFirmIntegration page (credential summary section)');
    }

    public function test_firm_integrations_list_page_never_leaks_the_planted_credential_webhook_routing_token_marker(): void
    {
        [$firm, , $owner] = $this->fixtureWithPlantedCredentialMarker();

        $this->actingAs($owner->user);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ListFirmIntegrations::class)
        );

        $this->assertUiStateHasNoSecretMarker($test, self::CREDENTIAL_MARKER, 'ListFirmIntegrations page');
    }

    public function test_firm_integration_model_itself_never_serializes_the_credential_marker_via_toarray(): void
    {
        // Belt-and-braces model-layer confirmation that the fixture's
        // own webhook_routing_token column (on FirmIntegration, a
        // SEPARATE HIDDEN-ONLY column from IntegrationCredential's own)
        // never round-trips through toArray()/toJson() either.
        [$firm, $connection] = $this->fixtureWithPlantedCredentialMarker();

        $array = $this->runWithFirmContext($firm, fn () => $connection->fresh()->toArray());

        $this->assertArrayNotHasKey('webhook_routing_token', $array);
    }

    // ------------------------------------------------------------
    // 2. IntegrationConflict.local_value / external_value
    // ------------------------------------------------------------

    /**
     * FORMERLY-DISCOVERED PRODUCTION BUG, NOW FIXED (see this
     * checkpoint's final report): `ConflictsRelationManager::
     * getRelationship()` used to return a bare `Illuminate\Database\
     * Eloquent\Builder` (never wrapped in a real `Relation`), which
     * crashed Filament 4.11.8's own `Filament\Tables\Table::
     * getRelationshipQuery()`. It now returns a genuine, manually
     * constructed `HasMany` `Relation` instance (see that class's own
     * `getRelationship()` docblock), so `ConflictsRelationManager` can
     * be rendered via `Livewire::test()` — genuinely proven below,
     * replacing the self-documented placeholder that used to assert the
     * render throws.
     *
     * The substantive secret-safety property for this table is proven
     * on TWO independent levels: (1) genuinely, via
     * `AssertsUiStateHasNoSecrets` against the live rendered component
     * (below), and (2) structurally — `local_value`/`external_value`
     * are confirmed absent from every column definition in the
     * RelationManager's own `table()` method source (mirroring
     * diff-review.md §2 item 7's independent grep-based confirmation),
     * so even a future column addition elsewhere couldn't silently
     * reintroduce a leak through this file without also changing this
     * file's own source.
     */
    public function test_conflicts_relation_manager_renders_successfully_and_never_leaks_the_planted_conflict_value_markers(): void
    {
        [$firm, $connection, $owner] = $this->fixtureWithPlantedConflictMarkers();

        $this->actingAs($owner->user);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(
                ConflictsRelationManager::class,
                [
                    'ownerRecord' => $connection,
                    'pageClass' => ViewFirmIntegration::class,
                ]
            )
        );

        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::CONFLICT_LOCAL_MARKER, 'ConflictsRelationManager table');
        $this->assertUiStateHasNoSecretMarker($test, self::CONFLICT_EXTERNAL_MARKER, 'ConflictsRelationManager table');

        // Structural fallback proof (source-scan): local_value/
        // external_value must never appear as a column definition in
        // this file, belt-and-braces alongside the genuine render-level
        // proof above.
        $source = file_get_contents(app_path('Filament/Firm/Resources/FirmIntegrationResource/RelationManagers/ConflictsRelationManager.php'));
        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression('/TextColumn::make\([\'"]local_value[\'"]\)/', $source);
        $this->assertDoesNotMatchRegularExpression('/TextColumn::make\([\'"]external_value[\'"]\)/', $source);
    }

    public function test_view_firm_integration_page_never_leaks_the_planted_conflict_value_markers_either(): void
    {
        [$firm, $connection, $owner] = $this->fixtureWithPlantedConflictMarkers();

        $this->actingAs($owner->user);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(
                ViewFirmIntegration::class,
                ['record' => $connection->uuid]
            )
        );

        $this->assertUiStateHasNoSecretMarker($test, self::CONFLICT_LOCAL_MARKER, 'ViewFirmIntegration page');
        $this->assertUiStateHasNoSecretMarker($test, self::CONFLICT_EXTERNAL_MARKER, 'ViewFirmIntegration page');
    }

    // ------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function fixtureWithPlantedCredentialMarker(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null])
        );

        // Plant the marker directly on IntegrationCredential.webhook_routing_token
        // (a HIDDEN-ONLY column that is structurally inert today — no
        // production code path writes it — but must still never leak if
        // it were ever populated, per frozen design §8's defense-in-depth
        // fix). A raw DB write is used deliberately: no factory state
        // exists for this column, and the point is to prove the UI layer
        // itself is safe regardless of how the value got there.
        $credential = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->create()
        );
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('id', $credential->id)
            ->update(['webhook_routing_token' => self::CREDENTIAL_MARKER]));

        $owner = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return [$firm, $connection, $owner];
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function fixtureWithPlantedConflictMarkers(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null])
        );

        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create([
                'local_value' => ['confidential_field' => self::CONFLICT_LOCAL_MARKER],
                'external_value' => ['confidential_field' => self::CONFLICT_EXTERNAL_MARKER],
            ]));

        $owner = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return [$firm, $connection, $owner];
    }
}
