<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\WebhookVerificationOutcome;
use App\Integrations\Models\IntegrationWebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationWebhookReceipt>
 *
 * integration_webhook_receipts has NO RLS at all (see its create
 * migration's docblock) and carries no `firm_id`/`firm_integration_id`
 * column, ever — this factory needs NO create() override / context-hold
 * convention: there is no tenant-scoped policy to satisfy and no
 * firm-owned dependency model to reconcile against, so a plain ordinary
 * insert (Factory's default create() behavior) is already correct.
 */
class IntegrationWebhookReceiptFactory extends Factory
{
    protected $model = IntegrationWebhookReceipt::class;

    public function definition(): array
    {
        $now = now();

        return [
            'provider_key' => 'test',
            'routing_token_hash' => hash('sha256', Str::random(43)),
            'request_correlation_id' => null,
            'provider_event_id' => (string) Str::uuid(),
            'body_hash' => hash('sha256', Str::random(64)),
            'signature_version' => 'v1',
            'verification_outcome' => WebhookVerificationOutcome::Verified->value,
            'received_at' => $now,
            'provider_timestamp' => $now,
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => $now,
            'processing_handoff_status' => 'pending',
            'failure_code' => null,
            'retention_deadline' => $now->copy()->addDays(7),
        ];
    }

    public function malformed(): static
    {
        return $this->state(fn () => [
            'provider_event_id' => null,
            'verification_outcome' => WebhookVerificationOutcome::Malformed->value,
            'failure_code' => 'malformed_payload',
        ]);
    }

    public function withRoutingTokenHash(string $routingTokenHash): static
    {
        return $this->state(fn () => ['routing_token_hash' => $routingTokenHash]);
    }

    public function withBodyHash(string $bodyHash): static
    {
        return $this->state(fn () => ['body_hash' => $bodyHash]);
    }
}
