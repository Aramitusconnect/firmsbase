<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantEncryptionKeyStatus;
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

    /**
     * Section 39A-3L test-isolation fix support — memoizes the single
     * FirmUser definition()'s initiating_user_id/initiating_firm_user_id
     * closures below must both derive from (never two independently
     * created FirmUsers), and the single encryption result its
     * verifier_ciphertext/encryption_key_id closures must both derive
     * from. Reset at the top of definition() itself so a bulk
     * ->count(N)->create() never leaks row 1's memoized values into row
     * 2+ (definition() is invoked once per row).
     */
    private ?FirmUser $lazyInitiatingFirmUser = null;

    private ?EmailBodyEncryptionResult $lazyEncryptionResult = null;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        // Section 39A-3L test-isolation fix: firm_id/firm_integration_id/
        // initiating_user_id/initiating_firm_user_id/verifier_ciphertext/
        // encryption_key_id used to be built by unconditionally calling
        // Firm::factory()->create(), FirmIntegration::factory()->forFirm()
        // ->create(), FirmUser::factory()->create(), and
        // encryptFixtureVerifier() (itself a real
        // TenantEncryptionKey::factory()->forFirm()->create() call) as
        // plain PHP statements at the top of this method — real,
        // committed side effects that ran even when forFirmIntegration()
        // below immediately overrides firm_id/firm_integration_id/
        // initiating_user_id/initiating_firm_user_id with a
        // caller-supplied connection. That also meant verifier_ciphertext/
        // encryption_key_id were silently encrypted against the WRONG
        // (wasted, about-to-be-discarded) firm whenever forFirmIntegration()
        // was used — a real, separate correctness gap this fix also
        // closes, not just the leak. Every field below is now a lazy
        // closure/factory-relationship: Laravel only resolves a
        // definition() value when it survives, unoverridden, to the final
        // merged attribute array (mirrors FirmIntegrationFactory's own
        // 'firm_id' => Firm::factory() pattern), so nothing here is
        // created unless it is actually going to be used. The two
        // memoized private properties above guarantee the
        // initiating_user_id/initiating_firm_user_id pair always comes
        // from the SAME FirmUser, and verifier_ciphertext/encryption_key_id
        // always come from the SAME encryption call, exactly as before —
        // never two independently created rows.
        $this->lazyInitiatingFirmUser = null;
        $this->lazyEncryptionResult = null;

        return [
            'firm_id' => Firm::factory(),
            'firm_integration_id' => fn (array $attributes) => FirmIntegration::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id']))
                ->create()
                ->id,
            'initiating_user_id' => function (array $attributes) {
                $this->lazyInitiatingFirmUser ??= FirmUser::factory()->create(['firm_id' => $attributes['firm_id']]);

                return $this->lazyInitiatingFirmUser->user_id;
            },
            'initiating_firm_user_id' => function (array $attributes) {
                $this->lazyInitiatingFirmUser ??= FirmUser::factory()->create(['firm_id' => $attributes['firm_id']]);

                return $this->lazyInitiatingFirmUser->id;
            },
            'opaque_token_hash' => hash('sha256', Str::random(43)),
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'verifier_ciphertext' => function (array $attributes) {
                $this->lazyEncryptionResult ??= $this->encryptFixtureVerifier(Firm::query()->findOrFail($attributes['firm_id']));

                return $this->lazyEncryptionResult->ciphertext;
            },
            'encryption_key_id' => function (array $attributes) {
                $this->lazyEncryptionResult ??= $this->encryptFixtureVerifier(Firm::query()->findOrFail($attributes['firm_id']));

                return $this->lazyEncryptionResult->encryptionKeyId;
            },
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
     *
     * Section 39A-3L test-isolation fix: only provisions a key when
     * $firm does not already have one. Before this factory's own leak
     * fix, this method was always called against a wasted, freshly
     * created, guaranteed-key-less Firm, so an unconditional create()
     * here never collided. Now that $firm is the row's real, final firm
     * (which several existing tests — e.g.
     * IntegrationOauthStatesForceRlsActivationTest::stateForFirm(),
     * called repeatedly for the SAME firm to create multiple oauth
     * states — deliberately reuse across multiple factory calls), an
     * unconditional create() would violate
     * tenant_encryption_keys_firm_id_key_version_unique the second time
     * around. EncryptionKeyService::provision() itself enforces "at
     * most one active key per firm" in production (throws if one
     * already exists) — this mirrors that exact invariant instead of
     * fighting it.
     */
    private function encryptFixtureVerifier(Firm $firm): EmailBodyEncryptionResult
    {
        $hasActiveKey = TenantEncryptionKey::query()
            ->where('firm_id', $firm->id)
            ->where('status', TenantEncryptionKeyStatus::Active)
            ->exists();

        if (! $hasActiveKey) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
        }

        $result = (new EmailBodyEncryptionService(new EncryptionKeyService))
            ->encrypt($firm, 'fixture-pkce-verifier-'.Str::random(43));

        if (! $result->succeeded) {
            throw new RuntimeException(
                "IntegrationOAuthStateFactory: failed to encrypt fixture verifier for firm {$firm->id}: {$result->reason}"
            );
        }

        return $result;
    }
}
