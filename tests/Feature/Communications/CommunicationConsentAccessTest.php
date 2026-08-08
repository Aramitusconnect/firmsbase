<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\CommunicationConsentsRelationManager;
use App\Filament\Firm\Resources\CommunicationConsentResource;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\CaptureClientConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\CaptureConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\RevokeConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Pages\ListCommunicationConsents;
use App\Filament\Firm\Resources\CommunicationConsentResource\RelationManagers\ConsentEventsRelationManager;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CommunicationConsentAccessTest — Firm Feature Manifest §16 item B4
 * (Communication Consent). Proves role ceilings, that "Record
 * Consent"/"Revoke" call ConsentService::capture()/revoke() for real
 * (never a bare CommunicationConsent::create()/update()), that
 * CommunicationConsentEvent rows are never directly writable via any
 * exposed UI action, that isGranted() is reused (not hand-rolled) for
 * the "currently contactable" indicator, and the small RLS regression
 * checklist required for this module.
 */
final class CommunicationConsentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. Standalone list page renders
    // ------------------------------------------------------------

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListCommunicationConsents::class));

        $test->assertSuccessful();
    }

    // ------------------------------------------------------------
    // 2. Role ceilings for Capture/Revoke (mirrors
    //    ClientCrmAccessPolicyService::INTAKE_ROLES exactly)
    // ------------------------------------------------------------

    public function test_capture_consent_action_is_visible_for_every_intake_role(): void
    {
        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListCommunicationConsents::class));
            $test->assertActionVisible(CaptureConsentAction::getDefaultName());
        }
    }

    public function test_capture_consent_action_is_hidden_for_billing_staff(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListCommunicationConsents::class));
        $test->assertActionHidden(CaptureConsentAction::getDefaultName());
    }

    public function test_revoke_action_is_hidden_for_billing_staff_on_a_granted_row(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $consent = $this->consentForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($consent): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->assertTableActionHidden(RevokeConsentAction::getDefaultName(), $consent);
        });
    }

    public function test_unauthorized_role_cannot_capture_consent_even_if_the_action_is_forced(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        // Same guarantee PaymentResourceAccessTest establishes: a hidden
        // action is ALSO disabled at the Filament framework level, so
        // mountAction() refuses to mount it at all — an unauthorized
        // role cannot even open the modal, let alone submit it.
        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->mountAction(CaptureConsentAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'channel' => 'email',
                'consent_text_version' => 'v1',
            ]);
            $test->callMountedAction();
            $test->assertActionHidden(CaptureConsentAction::getDefaultName());
        });

        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => CommunicationConsent::query()->count()));
    }

    // ------------------------------------------------------------
    // 3. Capture succeeds via ConsentService::capture() (never a bare
    //    CommunicationConsent::create()) — standalone + relation manager
    // ------------------------------------------------------------

    public function test_capture_consent_action_records_consent_via_consent_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Acme Corp']));

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->mountAction(CaptureConsentAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'channel' => 'email',
                'consent_text_version' => 'v1',
                'captured_via' => 'web_form',
                'expires_at' => null,
            ]);
            $test->callMountedAction();
            $test->assertNotified('Consent recorded');
        });

        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($consent);
        $this->assertSame(ConsentStatus::Granted, $consent->status);
        $this->assertSame(ConsentChannel::Email, $consent->channel);
        $this->assertSame('v1', $consent->consent_text_version);

        // Proof this went through ConsentService::capture(), not a bare
        // CommunicationConsent::create(): a paired CommunicationConsentEvent
        // row exists (this is the service's own internal, transactional
        // side effect — see ConsentService::capture()'s own source).
        $event = $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::query()->where('communication_consent_id', $consent->id)->first());
        $this->assertNotNull($event, 'A CommunicationConsentEvent must exist — proves ConsentService::capture() ran.');
        $this->assertSame('captured', $event->action);
        $this->assertSame('granted', $event->new_status);
    }

    public function test_capture_client_consent_action_on_the_client_tab_locks_the_client(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(CommunicationConsentsRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->mountTableAction(CaptureClientConsentAction::getDefaultName());
            $test->assertTableActionDataSet(['client_id' => $client->id]);
            $test->setActionData([
                'client_id' => $client->id,
                'channel' => 'sms',
                'consent_text_version' => 'v2',
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Consent recorded');
        });

        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::query()->where('client_id', $client->id)->where('channel', 'sms')->first());
        $this->assertNotNull($consent);
        $this->assertSame(ConsentStatus::Granted, $consent->status);
    }

    // ------------------------------------------------------------
    // 4. Recapture (same firm/client/channel) upserts, never
    //    duplicates, and logs a "recaptured" event
    // ------------------------------------------------------------

    public function test_capturing_the_same_channel_twice_recaptures_instead_of_duplicating(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $submit = function (string $version) use ($client): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->mountAction(CaptureConsentAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'channel' => 'email',
                'consent_text_version' => $version,
            ]);
            $test->callMountedAction();
            $test->assertNotified('Consent recorded');
        };

        $this->runWithFirmContext($firm, fn () => $submit('v1'));
        $this->runWithFirmContext($firm, fn () => $submit('v2'));

        $consents = $this->runWithFirmContext($firm, fn () => CommunicationConsent::query()->where('client_id', $client->id)->where('channel', 'email')->get());
        $this->assertCount(1, $consents, 'Recapturing the same firm/client/channel must upsert, never duplicate the row.');
        $this->assertSame('v2', $consents->first()->consent_text_version);

        $events = $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::query()->where('communication_consent_id', $consents->first()->id)->orderBy('id')->pluck('action')->all());
        $this->assertSame(['captured', 'recaptured'], $events);
    }

    // ------------------------------------------------------------
    // 5. Revoke — only ever on a Granted row, wired to
    //    ConsentService::revoke()
    // ------------------------------------------------------------

    public function test_revoke_action_is_visible_only_on_a_granted_row(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $granted = $this->consentForFirm($firm);
        $revoked = $this->consentForFirm($firm, ['status' => ConsentStatus::Revoked, 'granted_at' => now()->subDays(2), 'revoked_at' => now()->subDay()]);

        $this->runWithFirmContext($firm, function () use ($granted, $revoked): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->assertTableActionVisible(RevokeConsentAction::getDefaultName(), $granted);
            $test->assertTableActionHidden(RevokeConsentAction::getDefaultName(), $revoked);
        });
    }

    public function test_revoke_action_calls_consent_service_and_writes_a_paired_event(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $consent = $this->consentForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($consent): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->mountTableAction(RevokeConsentAction::getDefaultName(), $consent);
            $test->setActionData(['reason' => 'Client requested opt-out.']);
            $test->callMountedTableAction();
            $test->assertNotified('Consent revoked');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => CommunicationConsent::query()->find($consent->id));
        $this->assertSame(ConsentStatus::Revoked, $fresh->status);
        $this->assertNotNull($fresh->revoked_at);

        $event = $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::query()->where('communication_consent_id', $consent->id)->where('action', 'revoked')->first());
        $this->assertNotNull($event, 'A "revoked" CommunicationConsentEvent must exist — proves ConsentService::revoke() ran.');
        $this->assertSame('revoked', $event->new_status);
        $this->assertSame('granted', $event->previous_status);
    }

    // ------------------------------------------------------------
    // 6. CommunicationConsentEvent is never directly creatable/editable
    //    via any exposed UI action — it is written EXCLUSIVELY inside
    //    ConsentService, in the same transaction as its paired
    //    CommunicationConsent write (already proven functionally above
    //    for both capture() and revoke()). This test additionally
    //    proves no Filament source file in this module ever calls
    //    CommunicationConsentEvent::create()/update() directly, and that
    //    its own RelationManager exposes zero actions.
    // ------------------------------------------------------------

    public function test_no_filament_ui_source_ever_writes_a_communication_consent_event_directly(): void
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament/Firm/Resources/CommunicationConsentResource'), \FilesystemIterator::SKIP_DOTS)
        );
        $filamentSourceFiles = array_filter(iterator_to_array($directory), fn (\SplFileInfo $file): bool => $file->getExtension() === 'php');
        $filamentSourceFiles = array_map(fn (\SplFileInfo $file): string => $file->getPathname(), $filamentSourceFiles);
        $filamentSourceFiles[] = app_path('Filament/Firm/Resources/CommunicationConsentResource.php');
        $filamentSourceFiles[] = app_path('Filament/Firm/Resources/ClientResource/RelationManagers/CommunicationConsentsRelationManager.php');

        $this->assertNotEmpty($filamentSourceFiles);

        foreach ($filamentSourceFiles as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'CommunicationConsentEvent::create',
                $source,
                "{$file} must never write CommunicationConsentEvent directly — only ConsentService may.",
            );
            $this->assertStringNotContainsString(
                'CommunicationConsentEvent::update',
                $source,
                "{$file} must never update CommunicationConsentEvent directly — it is append-only.",
            );
        }
    }

    public function test_consent_events_relation_manager_exposes_no_mutating_actions(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $consent = $this->consentForFirm($firm);
        $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::factory()->forConsent($consent)->create());

        $this->runWithFirmContext($firm, function () use ($consent): void {
            $test = Livewire::test(ConsentEventsRelationManager::class, [
                'ownerRecord' => $consent,
                'pageClass' => CommunicationConsentResource\Pages\ViewCommunicationConsent::class,
            ]);
            $test->assertOk();
        });

        $source = file_get_contents(app_path('Filament/Firm/Resources/CommunicationConsentResource/RelationManagers/ConsentEventsRelationManager.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('->headerActions([])', $source);
        $this->assertStringContainsString('->recordActions([])', $source);
        $this->assertStringContainsString('->toolbarActions([])', $source);
    }

    // ------------------------------------------------------------
    // 7. isGranted() reuse — an expired-but-Granted-status row must
    //    show as NOT currently contactable via the model's own helper,
    //    never a hand-rolled expiry check in Filament.
    // ------------------------------------------------------------

    public function test_expired_but_granted_status_consent_is_not_currently_granted_via_the_model_helper(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->consentForFirm($firm, ['status' => ConsentStatus::Granted, 'granted_at' => now()->subDays(10), 'expires_at' => now()->subDay()]);

        // Sanity on the fixture itself: status is still Granted, but
        // expires_at is in the past.
        $this->assertSame(ConsentStatus::Granted, $consent->status);
        $this->assertTrue($consent->expires_at->isPast());

        // The real behavior every "is this client contactable" UI
        // indicator must reflect: isGranted() is false despite status
        // === Granted.
        $this->assertFalse($consent->isGranted(), 'isGranted() must account for expiry, not just status.');

        // The list page must render this row successfully (proves the
        // IconColumn state closure — which calls isGranted() — executes
        // without error against a real expired-but-Granted row).
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListCommunicationConsents::class));
        $test->assertCanSeeTableRecords([$consent]);
    }

    public function test_currently_granted_column_reuses_the_model_helper_not_a_hand_rolled_check(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Firm/Resources/CommunicationConsentResource.php'));
        $viewPageSource = file_get_contents(app_path('Filament/Firm/Resources/CommunicationConsentResource/Pages/ViewCommunicationConsent.php'));
        $relationManagerSource = file_get_contents(app_path('Filament/Firm/Resources/ClientResource/RelationManagers/CommunicationConsentsRelationManager.php'));

        foreach ([$resourceSource, $viewPageSource, $relationManagerSource] as $source) {
            $this->assertIsString($source);
            $this->assertStringContainsString('->isGranted()', $source, 'The "currently contactable" indicator must call CommunicationConsent::isGranted().');
        }
    }

    // ------------------------------------------------------------
    // 8. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own CommunicationConsent records. */
    public function test_a_firm_user_can_access_its_own_consents(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $consent = $this->consentForFirm($firm);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(CommunicationConsentResource::getUrl('view', ['record' => $consent])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's consent row is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_consents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $consentA = $this->consentForFirm($firmA);
        $consentB = $this->consentForFirm($firmB);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListCommunicationConsents::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$consentA]);
        $test->assertCanNotSeeTableRecords([$consentB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_consent_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentA = $this->consentForFirm($firmA);
        $consentB = $this->consentForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('communication_consents')->pluck('id')->all());

        $this->assertContains($consentA->id, $visibleIds);
        $this->assertNotContains($consentB->id, $visibleIds, "Firm A's session must never read Firm B's consent row.");
    }

    /** (c) a foreign client cannot be selected via the client_id relation select. */
    public function test_client_select_options_never_include_a_foreign_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $clientB): void {
            $visibleClientIds = Client::query()->pluck('id')->all();

            $this->assertContains($clientA->id, $visibleClientIds);
            $this->assertNotContains($clientB->id, $visibleClientIds, "Firm A's client_id options must never include Firm B's client.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_consent_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $consentB = $this->consentForFirm($firmB);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(CommunicationConsentResource::getUrl('view', ['record' => $consentB])));

        $response->assertNotFound();
    }

    /**
     * Creates a CommunicationConsent genuinely owned by the given firm —
     * i.e. its client is created `forFirm($firm)` first, then the
     * consent is created `forClient($client)`. Deliberately NOT
     * `CommunicationConsent::factory()->withClient()` on its own: that
     * state creates an entirely independent `Client::factory()->create()`
     * with its OWN random Firm (by design, per that factory state's own
     * purpose), which would silently produce a consent belonging to a
     * firm other than the one this test is asserting against — a bug
     * this method exists to avoid.
     */
    private function consentForFirm(Firm $firm, array $attributes = []): CommunicationConsent
    {
        $client = Client::factory()->forFirm($firm)->create();

        return CommunicationConsent::factory()->forClient($client)->state($attributes)->create();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
