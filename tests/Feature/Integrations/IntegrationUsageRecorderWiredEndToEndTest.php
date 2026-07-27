<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\ProviderRequestExecutor;
use App\Jobs\PushSyncJob;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * IntegrationUsageRecorderWiredEndToEndTest — Checkpoint 1 (FirmsVault
 * Live Integrations), checkpoint1-design-http-ratelimit-usage.md §6.4.
 * End-to-end proof that `integration_usage_records` is reachable through
 * a REAL dispatch path — App\Jobs\PushSyncJob::handle() ->
 * App\Integrations\Support\OutboundProviderHttpClient::execute() ->
 * a provider's push() -> App\Integrations\Support\ProviderRequestExecutor::send()
 * -> Http::fake() -> a genuine `integration_usage_records` row —
 * closing the gap the pre-construction inventory's §11 finding
 * described ("the table is genuinely empty in every environment
 * today").
 *
 * Uses a minimal fake provider whose push() method calls
 * ProviderRequestExecutor::send() directly, exactly the shape a real
 * Checkpoint 2-5 adapter's push() implementation will take — genuine
 * TestProvider never calls ProviderRequestExecutor at all (it is a pure
 * in-memory simulation with zero real HTTP), so it cannot exercise this
 * end-to-end path.
 */
final class IntegrationUsageRecorderWiredEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE_URL = 'https://sandbox-api.example.test';

    public function test_a_real_push_sync_job_dispatch_produces_a_correctly_attributed_usage_record(): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'sandbox',
            'sandbox_base_urls' => ['default' => self::SANDBOX_BASE_URL],
            'live_base_urls' => ['default' => 'https://live-api.example.test'],
        ]]);

        Http::fake([
            self::SANDBOX_BASE_URL.'/contacts' => Http::response(['external_id' => 'ext-e2e-1', 'version_token' => 'v1'], 200),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create([
                'status' => ConnectionStatus::Active->value,
                'external_account_id' => null,
            ]),
        );

        $this->registerRealHttpBackedPushProvider();

        // firm_integration_id is threaded through PushSyncJob's own
        // pre-existing $providerContext constructor parameter (JSON-
        // encoded per its own documented convention) so the fake
        // provider's push() below can resolve the FirmIntegration model
        // ProviderRequestExecutor::send() requires — no PushSyncJob
        // production code change, just using an existing extension
        // point.
        $job = new PushSyncJob(
            $connection->id,
            $firm->id,
            'contact',
            'App\\Models\\Contact',
            5001,
            'local-v1',
            providerContext: json_encode(['firm_integration_id' => $connection->id]),
        );
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );

        Http::assertSentCount(1);

        $rows = $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()
            ->where('firm_integration_id', $connection->id)
            ->get());

        $this->assertCount(1, $rows, 'A real PushSyncJob dispatch through a provider that calls ProviderRequestExecutor must write exactly one usage_records row.');

        $row = $rows->first();
        $this->assertSame($firm->id, $row->firm_id);
        $this->assertSame($connection->id, $row->firm_integration_id);
        $this->assertSame(ProviderKey::Test->value, $row->provider_key);
        $this->assertSame('push', $row->operation_type);
        $this->assertSame('success', $row->outcome);
    }

    private function registerRealHttpBackedPushProvider(): void
    {
        $provider = new class implements IntegrationProviderContract, SupportsPushSyncContract
        {
            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Real-HTTP-Backed Fake Provider (end-to-end usage-wiring proof)';
            }

            public function description(): string
            {
                return 'Calls ProviderRequestExecutor::send() for real, exactly like a genuine Checkpoint 2-5 adapter would.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }

            public function pushableResourceTypes(): array
            {
                return ['contact'];
            }

            public function push(array $context, string $resourceType, array $payload): array
            {
                $connection = FirmIntegration::query()->where('id', $context['firm_integration_id'] ?? null)->first();

                $response = app(ProviderRequestExecutor::class)->send(
                    connection: $connection,
                    providerKey: ProviderKey::Test,
                    method: 'POST',
                    url: 'https://sandbox-api.example.test/contacts',
                    capability: 'SupportsPushSyncContract',
                    operationType: 'push',
                    direction: SyncDirection::Outbound,
                    resourceType: ResourceType::Contact,
                    authInjector: fn ($r) => $r->withToken('fake-e2e-token'),
                    usageIdempotencyKey: 'push_operation:'.($payload['idempotency_key'] ?? 'e2e-fallback'),
                    body: $payload,
                );

                return [
                    'external_id' => $response->json['external_id'] ?? '',
                    'version_token' => $response->json['version_token'] ?? null,
                    'status' => 'synced',
                ];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }
}
