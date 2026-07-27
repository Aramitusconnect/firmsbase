<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SyncCursorEncryptionAnd410HandlingTest — FirmsVault Live Integrations,
 * Checkpoint 2 (test-writer pass).
 *
 * Covers three related, checkpoint2-combined-design.md §2 P-13/P-14/P-15
 * pieces:
 *  1. SyncCursorService::advance()/decryptCursorValue() — cursor_value is
 *     genuinely encrypted at rest, never stored as plaintext.
 *  2. SyncCursorService::invalidate() — THE REGRESSION TEST FOR SECURITY
 *     REVIEW FINDING 5: invalidate() must null BOTH cursor_value AND
 *     cursor_value_encryption_key_id in the SAME statement, or the
 *     integration_sync_cursors_value_key_id_pair CHECK constraint throws
 *     a raw QueryException on every real-world invalidation.
 *  3. PullSyncJob's CATEGORY_CURSOR_EXPIRED handling — a genuine Graph
 *     410 response invalidates the cursor (not just markFailed()), the
 *     run's terminal summary reflects the distinct
 *     cursor_expired_resync_required outcome, and the next dispatch
 *     against the now-Invalid cursor classifies as a Repair run.
 *  4. PullSyncJob's has_more-aware loop — zero-regression check that
 *     TestProvider's own behavior (which never sets has_more) is
 *     unchanged.
 */
final class SyncCursorEncryptionAnd410HandlingTest extends TestCase
{
    use RefreshDatabase;

    private function cursorService(): SyncCursorService
    {
        return new SyncCursorService(new EmailBodyEncryptionService(new EncryptionKeyService));
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->create();

        return $firm;
    }

    // ------------------------------------------------------------
    // 1. advance()/decryptCursorValue() — genuine encryption at rest
    // ------------------------------------------------------------

    public function test_advance_stores_cursor_value_as_ciphertext_and_decrypt_cursor_value_recovers_the_plaintext(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $service = $this->cursorService();
        $plaintext = 'https://graph.microsoft.com/v1.0/me/contacts/delta?$skiptoken=genuinely-plaintext-cursor-token';

        $advanced = $this->runWithFirmContext(
            $firm,
            fn () => $service->advance($connection, $cursor->id, $cursor->cursor_version, $plaintext),
        );

        $rawRow = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_sync_cursors')->where('id', $cursor->id)->first(),
        );

        $this->assertNotNull($rawRow->cursor_value, 'cursor_value must be persisted.');
        $this->assertNotSame($plaintext, $rawRow->cursor_value, 'cursor_value must be stored as ciphertext, never the raw plaintext.');
        $this->assertNotNull($rawRow->cursor_value_encryption_key_id, 'cursor_value_encryption_key_id must be set alongside a non-null cursor_value.');

        $decrypted = $this->runWithFirmContext($firm, fn () => $service->decryptCursorValue($connection, $advanced));

        $this->assertSame($plaintext, $decrypted, 'decryptCursorValue() must recover the original plaintext exactly.');
    }

    // ------------------------------------------------------------
    // 2. invalidate() — REGRESSION TEST FOR SECURITY REVIEW FINDING 5
    // ------------------------------------------------------------

    public function test_invalidate_after_a_real_advance_succeeds_without_a_check_constraint_violation_and_nulls_both_columns(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $service = $this->cursorService();

        // Advance to a REAL non-null value first, so
        // cursor_value_encryption_key_id is genuinely set — this is the
        // exact precondition the security review's Finding 5 bug
        // required to reproduce (a cursor that had previously advanced
        // at least once).
        $advanced = $this->runWithFirmContext(
            $firm,
            fn () => $service->advance($connection, $cursor->id, $cursor->cursor_version, 'a-real-non-null-cursor-value'),
        );

        $this->assertNotNull($advanced->cursor_value_encryption_key_id, 'Sanity check: cursor_value_encryption_key_id must genuinely be set before invalidate() is exercised — otherwise this test would not reproduce the bug Finding 5 describes.');

        // Before the fix, this call throws a raw QueryException (CHECK
        // constraint violation) because the old UPDATE only nulled
        // cursor_value, leaving cursor_value_encryption_key_id
        // non-null — violating
        // integration_sync_cursors_value_key_id_pair. No try/catch here
        // is deliberate: if the regression reappears, this call itself
        // throws and fails this test, which is the proof this test
        // exists to provide.
        $invalidated = $this->runWithFirmContext(
            $firm,
            fn () => $service->invalidate($cursor->id, $advanced->cursor_version),
        );

        $this->assertSame('cursor_invalid', $invalidated->status->value);
        $this->assertNull($invalidated->cursor_value, 'invalidate() must null cursor_value.');
        $this->assertNull($invalidated->cursor_value_encryption_key_id, 'invalidate() must null cursor_value_encryption_key_id in the SAME statement — this is the exact Finding 5 fix.');
    }

    // ------------------------------------------------------------
    // 3. PullSyncJob's CATEGORY_CURSOR_EXPIRED handling (Graph 410)
    // ------------------------------------------------------------

    private function microsoft365ConnectionWithCredential(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($providerRow)->create(['external_account_id' => null]),
        );

        $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->ofType(CredentialType::OauthAccessToken)->create(),
        );

        return $connection;
    }

    private function dispatchPull(FirmIntegration $connection, int $firmId, string $resourceType): void
    {
        $job = new PullSyncJob($connection->id, $firmId, $resourceType);
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(SyncCursorService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }

    public function test_a_graph_410_response_invalidates_the_cursor_and_the_runs_terminal_summary_reflects_cursor_expired(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/contacts/delta' => Http::response(
                ['error' => ['code' => 'ResyncRequired', 'message' => 'The delta token is expired.']],
                410,
            ),
        ]);

        config(['integrations.providers' => [ProviderKey::Microsoft365->value => Microsoft365Provider::class]]);

        $firm = $this->firmWithActiveKey();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $this->dispatchPull($connection, $firm->id, ResourceType::Contact->value);

        $cursorRow = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_sync_cursors')
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', ResourceType::Contact->value)
                ->where('sync_direction', SyncDirection::Inbound->value)
                ->first(),
        );

        $this->assertNotNull($cursorRow);
        $this->assertSame('cursor_invalid', $cursorRow->status, 'A 410 response must invalidate the cursor, not just mark it failed.');
        $this->assertNull($cursorRow->cursor_value);
        $this->assertNull($cursorRow->cursor_value_encryption_key_id);

        $runRow = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_sync_runs')
                ->where('firm_integration_id', $connection->id)
                ->orderByDesc('id')
                ->first(),
        );

        $this->assertNotNull($runRow);
        $this->assertSame('failed', $runRow->status);
        $this->assertSame('pull_failed: cursor_expired_resync_required', $runRow->error_summary, 'The terminal summary must reflect the distinct cursor_expired_resync_required outcome, not a generic pull_failed category string.');

        // A subsequent sync run against this now-Invalid cursor must be
        // classified as a Repair run — SyncRunService::determineRunType()'s
        // existing, already-tested behavior; this proves the
        // INTEGRATION (the cursor really is Invalid, and starting a new
        // run against it really does classify as Repair), not a
        // reimplementation of that logic.
        $freshCursor = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationSyncCursor::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', ResourceType::Contact->value)
                ->where('sync_direction', SyncDirection::Inbound->value)
                ->firstOrFail(),
        );

        $repairRun = $this->runWithFirmContext(
            $firm,
            fn () => app(SyncRunService::class)->startRun(
                $connection,
                ResourceType::Contact->value,
                SyncDirection::Inbound,
                SyncTriggerSource::Webhook,
                $freshCursor,
            ),
        );

        $this->assertSame(SyncRunType::Repair, $repairRun->run_type);
    }

    // ------------------------------------------------------------
    // 4. has_more-aware loop — zero-regression check for TestProvider
    // ------------------------------------------------------------

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $itemsPerPage
     */
    private function registerFakePullProvider(array $itemsPerPage): void
    {
        $provider = new class($itemsPerPage) implements IntegrationProviderContract, SupportsPullSyncContract
        {
            public function __construct(private readonly array $itemsPerPage) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Pull Provider (no has_more key)';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider that never sets has_more.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }

            public function pullableResourceTypes(): array
            {
                return ['contact'];
            }

            public function pull(array $context, string $resourceType, ?string $cursor): array
            {
                $pageIndex = $cursor === null ? 0 : (int) $cursor;
                $items = $this->itemsPerPage[$pageIndex] ?? [];
                $nextCursor = array_key_exists($pageIndex + 1, $this->itemsPerPage) ? (string) ($pageIndex + 1) : null;

                // Deliberately no 'has_more' key at all — proves
                // PullSyncJob's has_more-aware loop condition
                // (`$page['has_more'] ?? ($pageCursor !== null)`) falls
                // through to the exact pre-Checkpoint-2 behavior.
                return ['items' => $items, 'next_cursor' => $nextCursor];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    public function test_a_provider_that_never_sets_has_more_advances_across_pages_exactly_as_before(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null]));

        $this->registerFakePullProvider([
            [['external_id' => 'no-has-more-p0', 'version_token' => 'v1']],
            [['external_id' => 'no-has-more-p1', 'version_token' => 'v1']],
        ]);

        $this->dispatchPull($connection, $firm->id, 'contact');

        $cursorRow = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_sync_cursors')
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', 'contact')
                ->first(),
        );

        $this->assertNotNull($cursorRow);
        $this->assertSame(2, (int) $cursorRow->cursor_version, 'Two pages, neither setting has_more, must still advance the cursor twice — unchanged from pre-Checkpoint-2 behavior.');
        $this->assertNull($cursorRow->cursor_value, 'The final page\'s null next_cursor must still end the walk with cursor_value null.');
        $this->assertSame('idle', $cursorRow->status);
    }
}
