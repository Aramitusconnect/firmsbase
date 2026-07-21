<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOAuthState;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TenantContextService;
use App\ValueObjects\EmailBodyEncryptionResult;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<IntegrationOAuthState>
 *
 * integration_oauth_states has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. This create() override mirrors
 * FirmIntegrationFactory/IntegrationCredentialFactory's exact
 * context-hold convention: groups resolved models by firm_id and
 * activates the matching PostgreSQL session context per group before
 * inserting.
 *
 * `initiating_user_id`/`initiating_firm_user_id` are ALWAYS resolved
 * together from the SAME freshly-created FirmUser row (never
 * independently) — required by IntegrationOAuthState's own `saving`
 * listener (see that model's class docblock), which rejects a row
 * where the two disagree.
 *
 * `verifier_ciphertext`/`encryption_key_id` are produced by routing a
 * fixture plaintext through the REAL EncryptionKeyService/
 * EmailBodyEncryptionService chain (mirrors
 * IntegrationCredentialFactory::encryptFixtureSecret() exactly) — never
 * a hardcoded ciphertext-shaped placeholder string — so a
 * factory-created row's verifier is genuinely decryptable in tests that
 * need a real round trip.
 *
 * `opaque_token_hash` is a fixture sha256 digest of a fake random
 * string — this factory has no reason to know, or reconstruct, a real
 * raw state value (which this table never persists at all, by design;
 * see IntegrationOAuthState's class docblock).
 */
class IntegrationOAuthStateFactory extends Factory
{
    protected $model = IntegrationOAuthState::class;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        $firm = Firm::factory()->create();

        $firmIntegration = FirmIntegration::factory()->forFirm($firm)->create();

        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $encryptionResult = $this->encryptFixtureVerifier($firm);

        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $firmIntegration->id,
            'initiating_user_id' => $firmUser->user_id,
            'initiating_firm_user_id' => $firmUser->id,
            'opaque_token_hash' => hash('sha256', Str::random(43)),
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'verifier_ciphertext' => $encryptionResult->ciphertext,
            'encryption_key_id' => $encryptionResult->encryptionKeyId,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    /**
     * Overrides firm_id, firm_integration_id, AND the
     * initiating_user_id/initiating_firm_user_id pair together (never
     * independently settable) so a caller can never end up with a
     * fixture where any of these disagree — mirrors
     * FirmIntegrationFactory::forFirm()/IntegrationCredentialFactory::forFirmIntegration()'s
     * identical discipline.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(function () use ($firmIntegration) {
            $firmUser = FirmUser::factory()->create(['firm_id' => $firmIntegration->firm_id]);

            return [
                'firm_id' => $firmIntegration->firm_id,
                'firm_integration_id' => $firmIntegration->id,
                'initiating_user_id' => $firmUser->user_id,
                'initiating_firm_user_id' => $firmUser->id,
            ];
        });
    }

    /**
     * Overrides ONLY the initiating identity pair (never firm_id/
     * firm_integration_id) — for tests that need a specific,
     * already-existing FirmUser as the initiator (e.g. the callback
     * self-lookup test), while still guaranteeing the pair agrees with
     * each other per the model's own listener.
     */
    public function initiatedBy(FirmUser $firmUser): static
    {
        return $this->state(fn () => [
            'initiating_user_id' => $firmUser->user_id,
            'initiating_firm_user_id' => $firmUser->id,
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'consumed_at' => now(),
            'verifier_ciphertext' => null,
            'encryption_key_id' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * Provisions a real, Active TenantEncryptionKey for $firm (via
     * TenantEncryptionKeyFactory::forFirm(), the same context-hold
     * convention every other FORCE-RLS factory in this codebase uses)
     * and routes a fixture PKCE-verifier-shaped plaintext through the
     * real EncryptionKeyService/EmailBodyEncryptionService chain —
     * never fabricates a ciphertext-shaped string by hand. Mirrors
     * IntegrationCredentialFactory::encryptFixtureSecret() exactly.
     */
    private function encryptFixtureVerifier(Firm $firm): EmailBodyEncryptionResult
    {
        TenantEncryptionKey::factory()->forFirm($firm)->create();

        $result = (new EmailBodyEncryptionService(new EncryptionKeyService()))
            ->encrypt($firm, 'fixture-pkce-verifier-'.Str::random(43));

        if (! $result->succeeded) {
            throw new RuntimeException(
                "IntegrationOAuthStateFactory: failed to encrypt fixture verifier for firm {$firm->id}: {$result->reason}"
            );
        }

        return $result;
    }
}
