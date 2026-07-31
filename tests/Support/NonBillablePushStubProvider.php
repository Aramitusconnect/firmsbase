<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;
use Closure;

/**
 * NonBillablePushStubProvider — the push-sync sibling of
 * NonBillableWebhookStubProvider. No provider `SupportsPushSyncContract`
 * implementer also implements `RequiresBillableCallPipelineContract`
 * (only Plaid does, and Plaid never implements
 * `SupportsPushSyncContract`), so this stub stands in for the DIRECT
 * push call every real Microsoft 365 / Google Workspace push takes —
 * the path Checkpoint 8.2's durable gate now protects.
 *
 * Keyed as Microsoft365 so it stands in for a real push-capable
 * connection. push() is a counting closure supplied by the test; no
 * real credential, no real HTTP.
 */
final class NonBillablePushStubProvider implements IntegrationProviderContract, SupportsPushSyncContract
{
    public int $pushCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $pushPayloads = [];

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public Closure $onPush;

    public function __construct()
    {
        $this->onPush = static fn (): array => [
            'external_id' => 'push-stub-external-id',
            'version_token' => 'push-stub-version-token',
        ];
    }

    public function key(): ProviderKey
    {
        return ProviderKey::Microsoft365;
    }

    public function displayName(): string
    {
        return 'Non-Billable Push Stub';
    }

    public function description(): string
    {
        return 'Test-only provider standing in for the direct (non-pipeline) push-sync path.';
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
        $this->pushCalls++;
        $this->pushPayloads[] = $payload;

        return ($this->onPush)($payload);
    }
}
