<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TenantContextService;
use App\ValueObjects\EmailBodyEncryptionResult;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * @extends Factory<IntegrationCredential>
 *
 * integration_credentials has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. This create() override mirrors
 * FirmIntegrationFactory/TenantEncryptionKeyFactory's exact context-hold
 * convention (see database/factories/FirmIntegrationFactory.php): groups
 * resolved models by firm_id and activates the matching PostgreSQL
 * session context per group before inserting.
 *
 * Unlike FirmAiProviderKeyFactory/WebhookSecretFactory (which both use a
 * hardcoded 'placeholder-ciphertext-not-real' literal, explicitly
 * disclosed as not genuinely decryptable) and unlike
 * EmailOAuthTokenFactory (which encrypts via the bare Crypt facade/
 * APP_KEY rather than the module's real per-firm envelope),
 * definition() below actually provisions a real, persisted, Active
 * TenantEncryptionKey for the target firm (via
 * TenantEncryptionKeyFactory::forFirm() — itself a genuinely
 * decryptable key, since that factory's own encrypted_key is a real
 * Crypt::encryptString() of real random key material, not a
 * placeholder) and routes a fixture plaintext through the real
 * EncryptionKeyService/EmailBodyEncryptionService chain. A
 * factory-created row's ciphertext is therefore genuinely decryptable
 * via IntegrationCredentialService::decryptForOperation()/reEncrypt()
 * in tests that need a real round trip — never a hardcoded
 * ciphertext-shaped string masquerading as real ciphertext.
 */
class IntegrationCredentialFactory extends Factory
{
    protected $model = IntegrationCredential::class;

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

        $encryptionResult = $this->encryptFixtureSecret($firm);

        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $firmIntegration->id,
            'credential_type' => CredentialType::OauthAccessToken->value,
            'encrypted_payload_ciphertext' => $encryptionResult->ciphertext,
            'encryption_key_id' => $encryptionResult->encryptionKeyId,
            'status' => IntegrationCredentialStatus::Active->value,
            'granted_scopes_json' => ['test.read', 'test.write'],
            'expires_at' => now()->addHour(),
            'masked_display_metadata' => ['label' => fake()->company().' Test Credential'],
            'webhook_routing_token' => null,
            'rotated_at' => null,
            'revoked_at' => null,
            'last_refreshed_at' => null,
            'refresh_failure_reason' => null,
        ];
    }

    /**
     * Overrides BOTH firm_id and firm_integration_id together (never
     * independently settable) so a caller can never end up with a
     * fixture where this credential's firm_id disagrees with its
     * firm_integration's real owning firm — mirrors
     * FirmIntegrationFactory::forFirm() setting firm_id and
     * connected_by_firm_user_id together for the identical reason.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
        ]);
    }

    public function ofType(CredentialType $type): static
    {
        return $this->state(fn () => ['credential_type' => $type->value]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => [
            'status' => IntegrationCredentialStatus::Rotated->value,
            'rotated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => IntegrationCredentialStatus::Revoked->value,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    /**
     * Provisions a real, Active TenantEncryptionKey for $firm (via
     * TenantEncryptionKeyFactory::forFirm(), the same context-hold
     * convention every other FORCE-RLS factory in this codebase uses)
     * and routes a fixture plaintext through the real
     * EncryptionKeyService/EmailBodyEncryptionService chain — never
     * fabricates a ciphertext-shaped string by hand.
     */
    private function encryptFixtureSecret(Firm $firm): EmailBodyEncryptionResult
    {
        TenantEncryptionKey::factory()->forFirm($firm)->create();

        $result = (new EmailBodyEncryptionService(new EncryptionKeyService()))
            ->encrypt($firm, 'fixture-integration-credential-secret-'.fake()->uuid());

        if (! $result->succeeded) {
            throw new RuntimeException(
                "IntegrationCredentialFactory: failed to encrypt fixture secret for firm {$firm->id}: {$result->reason}"
            );
        }

        return $result;
    }
}
