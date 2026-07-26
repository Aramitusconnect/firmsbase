<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationWebhookRoutingIndex>
 *
 * integration_webhook_routing_index has NO RLS at all (see its create
 * migration's "WHY THIS TABLE HAS NO RLS" docblock) — this factory
 * needs NO create() override / context-hold convention, unlike every
 * FORCE-RLS factory in this codebase (e.g. IntegrationOAuthStateFactory):
 * there is no tenant-scoped policy to satisfy, so a plain ordinary
 * insert (Factory's default create() behavior) is already correct.
 * `FirmIntegration::factory()` (a genuinely FORCE-RLS table) manages
 * its own context internally via its own factory's create() override —
 * this factory does not need to, and must not, duplicate that.
 *
 * `webhook_routing_token_hash` is a fixture sha256 digest of a fake
 * random string — this factory has no reason to know, or reconstruct,
 * a real raw routing token (which this table never stores at all, by
 * design).
 */
class IntegrationWebhookRoutingIndexFactory extends Factory
{
    protected $model = IntegrationWebhookRoutingIndex::class;

    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * FirmIntegration::factory()->create() as a plain PHP statement at
     * the top of definition() — a real, committed FirmIntegration (+ its
     * own nested Firm) every single time, even when forFirmIntegration()
     * below immediately overrides firm_id/firm_integration_id/
     * integration_provider_id with a caller-supplied connection. Fixed
     * by memoizing the connection behind lazy closures so nothing is
     * created unless at least one of those keys survives, unoverridden,
     * to the final row.
     */
    private ?FirmIntegration $lazyFirmIntegration = null;

    public function definition(): array
    {
        $this->lazyFirmIntegration = null;

        return [
            'firm_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->firm_id;
            },
            'firm_integration_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->id;
            },
            'integration_provider_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->integration_provider_id;
            },
            'webhook_routing_token_hash' => hash('sha256', Str::random(43)),
        ];
    }

    /**
     * Overrides firm_id/firm_integration_id/integration_provider_id
     * together (never independently settable) so a caller can never
     * end up with a fixture where these three disagree — mirrors
     * FirmIntegrationFactory::forFirm()'s identical discipline.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
            'integration_provider_id' => $firmIntegration->integration_provider_id,
        ]);
    }

    /**
     * Sets the row's hash directly from an already-known digest. The
     * raw routing token itself is never a column on this model (by
     * design — see the create migration) and this factory therefore
     * has no method that accepts one: a test that needs a real raw
     * token/hash pair (e.g. to exercise
     * WebhookConnectionResolverService::resolveConnectionIdentity()
     * end to end) must generate the raw token itself and pass
     * `hash('sha256', $rawToken)` here.
     */
    public function withTokenHash(string $webhookRoutingTokenHash): static
    {
        return $this->state(fn () => ['webhook_routing_token_hash' => $webhookRoutingTokenHash]);
    }
}
