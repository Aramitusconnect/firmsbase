<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\EndToEnd;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TestProviderConnectPullConflictDisconnectScenarioTest — Checkpoint 12
 * (frozen-design-post-security-review.md §6 Scenario 1). The headline
 * end-to-end harness scenario: initiate OAuth -> simulateAuthorizationGrant()
 * -> real HTTP GET to the real `integrations.oauth.callback` route ->
 * enableWebhookRouting() -> a real, queue-processed PullSyncJob dispatch
 * (F2's providerContext, `simulate_pages: 2`) -> real mapping/cursor
 * state -> a second real pull producing exactly one genuine
 * IntegrationConflict row (`simulate_conflict_for`) -> the SAME conflict
 * visible through the real SuperAdmin conflict view -> disconnect, with
 * credential_revoked audit rows and no-context/cross-firm denial proven
 * along the way.
 *
 * Every fixture-creation call below is individually wrapped in
 * runWithFirmContext() (frozen design §6's mandatory discipline) — never
 * relying on a FORCE-RLS factory's own unrestored
 * TenantContextService::setDatabaseTenantContextForFirmId() call.
 *
 * Real queue processing, not Bus::fake(): phpunit.xml pins
 * QUEUE_CONNECTION=sync, so PullSyncJob::dispatch(...) below runs
 * handle() synchronously, in-process, through the real queue dispatcher
 * and the real container — never Bus::fake()'d, never manually
 * newed-up-and-handle()'d.
 */
final class TestProviderConnectPullConflictDisconnectScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_the_full_connect_pull_conflict_admin_view_disconnect_chain(): void
    {
        // ------------------------------------------------------------
        // Step 1: connect via a REAL HTTP GET to the real
        // integrations.oauth.callback route (not merely the service
        // layer — OAuthConnectionControllerCallbackRouteTest.php proves
        // that route in isolation; here it is one link in the full
        // chain).
        // ------------------------------------------------------------
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $flow = $this->initiateFlow($connection, $firmUser);
        $code = (new TestProvider)->simulateAuthorizationGrant($flow['codeChallenge']);

        $callbackResponse = $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        $callbackResponse->assertRedirect(route('filament.firm.resources.firm-integrations.view', ['record' => $connection]));

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $connection->status, 'The real HTTP callback must have activated the connection before any sync step proceeds.');

        // ------------------------------------------------------------
        // Step 2: enableWebhookRouting() — proves the chain's next real
        // step composes (Scenario 2 exercises the actual webhook POST
        // itself).
        // ------------------------------------------------------------
        $rawWebhookToken = $this->service()->enableWebhookRouting($connection, $firmUser->user_id);
        $this->assertNotEmpty($rawWebhookToken);

        // ------------------------------------------------------------
        // Step 3: first real, queue-processed PullSyncJob dispatch with
        // F2's providerContext (simulate_pages: 2) against genuine
        // TestProvider — real mapping/cursor state.
        // ------------------------------------------------------------
        $this->assertNoDatabaseTenantContext('Sanity check: no ambient tenant context should be active before dispatch.');

        PullSyncJob::dispatch($connection->id, $firm->id, 'contact', providerContext: json_encode(['simulate_pages' => 2]));

        $this->assertNoDatabaseTenantContext('PullSyncJob::handle()\'s runInFirmContext() must restore no-context after a real dispatch completes.');

        $cursor = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_cursors')
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', 'contact')
            ->first());
        $this->assertNotNull($cursor);
        $this->assertSame(2, (int) $cursor->cursor_version, 'Two genuinely fetched TestProvider pages must advance the cursor twice.');
        $this->assertNull($cursor->cursor_value, 'The second (final) page\'s next_cursor is null.');

        $firstRun = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
        $this->assertSame('succeeded', $firstRun->status);

        // Note: integration_sync_runs.items_total/items_succeeded/etc
        // are never written by SyncRunService::transitionStatus() or
        // SyncItemService::recordAttempt() (confirmed by reading both in
        // full) — the real per-item record lives exclusively in
        // integration_sync_items, asserted directly below.
        $itemsCount = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('sync_run_id', $firstRun->id)->count());
        $this->assertSame(4, $itemsCount, 'Two genuinely fetched TestProvider pages x two items per page — every genuinely pulled external item must be recorded as a real sync item, none silently dropped.');

        // ------------------------------------------------------------
        // Step 4: pre-seed a local record's mapping that will disagree
        // with the SECOND pull's forced (simulate_conflict_for) item —
        // a genuine version disagreement, not a fabricated
        // IntegrationConflict fixture.
        // ------------------------------------------------------------
        $conflictingExternalId = 'end-to-end-conflict-'.$connection->id;

        $preExistingMapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 555001,
                'external_id' => $conflictingExternalId,
                'external_version_token' => 'local-known-version',
            ]));

        PullSyncJob::dispatch($connection->id, $firm->id, 'contact', providerContext: json_encode(['simulate_conflict_for' => $conflictingExternalId]));

        $conflicts = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 555001)
            ->get());

        $this->assertCount(1, $conflicts, 'Exactly one genuine IntegrationConflict row must be created from the real simulate_conflict_for disagreement.');
        $conflict = $conflicts->first();
        $this->assertSame('remote_version_changed', $conflict->conflict_type);
        $this->assertSame('detected', $conflict->status->value);

        $freshMapping = $this->runWithFirmContext($firm, fn () => $preExistingMapping->fresh());
        $this->assertSame('local-known-version', $freshMapping->external_version_token, 'The disagreement must never silently overwrite the mapping\'s stored version token.');

        // ------------------------------------------------------------
        // Step 5: the SAME conflict id is visible through the real
        // SuperAdmin conflict view (Checkpoint 11's
        // PlatformFirmIntegrationDetailPage).
        // ------------------------------------------------------------
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $conflictRows = app(IntegrationPlatformOversightReadService::class)->conflictsForConnection($admin, $firm, $connection->id);
        $matchingRow = $conflictRows->firstWhere('id', $conflict->id);
        $this->assertNotNull($matchingRow, 'The read service backing the SuperAdmin conflict view must surface the SAME conflict id created above.');
        $this->assertSame('remote_version_changed', $matchingRow['conflict_type']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $page = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $page->assertOk();
        $page->assertSee('remote_version_changed');
        $page->assertSee('contact');

        // ------------------------------------------------------------
        // Step 6: no-context and cross-firm denial hold at this point in
        // the scenario (not merely at the very start of an isolated
        // test) — a session with zero ambient tenant context cannot see
        // this connection's credentials, and a different firm's actor
        // cannot dispatch a pull against it.
        // ------------------------------------------------------------
        (new TenantContextService)->clearDatabaseTenantContext();
        $noContextCredentialRows = DB::table('integration_credentials')->where('firm_integration_id', $connection->id)->get();
        $this->assertCount(0, $noContextCredentialRows, 'FORCE RLS must deny an ordinary no-context session, independent of the connection id being known.');

        $attackerFirm = $this->firmWithActiveKey();
        try {
            PullSyncJob::dispatchSync($connection->id, $attackerFirm->id, 'contact');
            $this->fail('A pull dispatch claiming the wrong firm id must be denied.');
        } catch (ModelNotFoundException) {
            // expected — the job's own ->where('firm_id', ...) guard
            // (plus RLS) finds zero rows for the wrong firm.
        }

        // ------------------------------------------------------------
        // Step 7: disconnect — credential_revoked audit rows exist, and
        // cross-firm disconnect denial holds.
        // ------------------------------------------------------------
        $attackerFirmUser = $this->firmUserFor($attackerFirm, FirmUserRole::FirmOwner);

        try {
            $this->service()->disconnect($this->runWithFirmContext($firm, fn () => $connection->fresh()), $attackerFirmUser->user_id);
            $this->fail('A cross-firm actor must never be able to disconnect another firm\'s connection.');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status, 'The denied cross-firm disconnect attempt must not have mutated the connection.');

        $this->service()->disconnect($fresh, $firmUser->user_id);

        $disconnected = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Disconnected, $disconnected->status);

        $revokedEvents = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.credential_revoked')
            ->where('subject_id', $connection->id)
            ->get());
        $this->assertNotEmpty($revokedEvents, 'A genuine disconnect at the end of this chain must record credential_revoked audit rows.');

        $activeCredentialCount = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(0, $activeCredentialCount);
    }

    // ------------------------------------------------------------
    // Helpers — mirrors ProviderConnectionServiceOAuthTest's established
    // shapes, kept self-contained in this file per the frozen design's
    // "touch only the test-file allowlist" discipline (no shared trait
    // introduced).
    // ------------------------------------------------------------

    private function service(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder),
            new IntegrationAccessPolicyService(new TimelineEventRecorder),
            new ProviderRegistry,
            new OutboundProviderHttpClient,
            new ProviderRedirectUrlValidator,
            new TimelineEventRecorder,
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
        );
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    /**
     * @return array{rawState: string, codeChallenge: string}
     */
    private function initiateFlow(FirmIntegration $connection, FirmUser $firmUser): array
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        return [
            'rawState' => $query['state'],
            'codeChallenge' => $query['code_challenge'],
        ];
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
