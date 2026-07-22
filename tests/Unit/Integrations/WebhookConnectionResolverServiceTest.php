<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\WebhookConnectionResolverService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WebhookConnectionResolverServiceTest — Checkpoint 7. Exercises Steps
 * 1-3 of the frozen design's four-step identity-scoped secret-
 * resolution mechanism
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5)
 * directly against the real service and a real PostgreSQL database —
 * no HTTP layer involved.
 */
final class WebhookConnectionResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $encryptionKeyIds = [];

    // ------------------------------------------------------------
    // Step 1 — resolveConnectionIdentity()
    // ------------------------------------------------------------

    public function test_unknown_provider_resolves_to_null(): void
    {
        $result = $this->resolver()->resolveConnectionIdentity('does-not-exist', Str::random(43));

        $this->assertNull($result);
    }

    public function test_unknown_token_for_a_known_provider_resolves_to_null(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $this->enableRouting($connection);

        $result = $this->resolver()->resolveConnectionIdentity('test', Str::random(43));

        $this->assertNull($result);
    }

    public function test_a_valid_provider_and_token_resolves_to_the_correct_identity(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $rawToken = $this->enableRouting($connection);

        $result = $this->resolver()->resolveConnectionIdentity('test', $rawToken);

        $this->assertInstanceOf(ResolvedWebhookConnection::class, $result);
        $this->assertSame($firm->id, $result->firmId);
        $this->assertSame($connection->id, $result->firmIntegrationId);
        $this->assertSame('test', $result->providerKey);
    }

    public function test_a_token_valid_for_one_provider_does_not_resolve_under_a_different_providers_segment(): void
    {
        [, $connection] = $this->connectionForFirm();
        $rawToken = $this->enableRouting($connection);

        $otherProvider = IntegrationProvider::factory()->create();

        $result = $this->resolver()->resolveConnectionIdentity($otherProvider->code, $rawToken);

        $this->assertNull($result, 'A token hash lookup must be scoped by BOTH the provider and the hash — matching the hash alone under a different provider segment must not resolve.');
    }

    public function test_a_firm_bs_token_never_resolves_to_firm_as_identity(): void
    {
        [$firmA, $connectionA] = $this->connectionForFirm();
        [$firmB, $connectionB] = $this->connectionForFirm();
        $this->enableRouting($connectionA);
        $tokenB = $this->enableRouting($connectionB);

        $result = $this->resolver()->resolveConnectionIdentity('test', $tokenB);

        $this->assertSame($firmB->id, $result->firmId);
        $this->assertNotSame($firmA->id, $result->firmId);
    }

    public function test_an_off_by_one_character_mutation_of_a_real_token_does_not_resolve(): void
    {
        [, $connection] = $this->connectionForFirm();
        $rawToken = $this->enableRouting($connection);

        $lastChar = $rawToken[strlen($rawToken) - 1];
        $mutated = substr($rawToken, 0, -1).($lastChar === 'a' ? 'b' : 'a');

        $this->assertNull($this->resolver()->resolveConnectionIdentity('test', $mutated));
    }

    public function test_resolving_connection_identity_never_queries_integration_credentials(): void
    {
        [, $connection] = $this->connectionForFirm();
        $rawToken = $this->enableRouting($connection);

        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        $this->resolver()->resolveConnectionIdentity('test', $rawToken);

        $touchesCredentials = array_filter($capturedSql, fn ($sql) => str_contains($sql, 'integration_credentials'));

        $this->assertEmpty(
            $touchesCredentials,
            'Step 1 (resolveConnectionIdentity) must never touch integration_credentials at all — no query path exists for a credential_type-scoped predicate to hide behind.'
        );
    }

    // ------------------------------------------------------------
    // Steps 2-3 — activeAndPreviousWebhookSecretsFor()
    // ------------------------------------------------------------

    public function test_a_disconnected_connection_yields_zero_candidates(): void
    {
        [, $connection] = $this->connectionForFirm(ConnectionStatus::Disconnected);
        $resolved = new ResolvedWebhookConnection($connection->firm_id, $connection->id, $connection->integration_provider_id, 'test');

        $this->assertSame([], $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved));
    }

    public function test_a_connection_with_no_webhook_signing_secret_at_all_yields_zero_candidates(): void
    {
        [, $connection] = $this->connectionForFirm();
        $resolved = new ResolvedWebhookConnection($connection->firm_id, $connection->id, $connection->integration_provider_id, 'test');

        $this->assertSame([], $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved));
    }

    public function test_an_active_only_secret_yields_exactly_one_candidate_matching_the_plaintext(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $plaintext = 'active-secret-'.Str::random(24);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::WebhookSigningSecret, $plaintext));

        $resolved = new ResolvedWebhookConnection($firm->id, $connection->id, $connection->integration_provider_id, 'test');
        $candidates = $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved);

        $this->assertSame([$plaintext], $candidates);
    }

    public function test_a_rotated_secret_within_the_overlap_window_yields_two_candidates_active_first(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $original = 'original-secret-'.Str::random(24);
        $replacement = 'replacement-secret-'.Str::random(24);

        $credential = $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::WebhookSigningSecret, $original));
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->rotate($connection, $credential, $replacement));

        $resolved = new ResolvedWebhookConnection($firm->id, $connection->id, $connection->integration_provider_id, 'test');
        $candidates = $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved);

        $this->assertSame([$replacement, $original], $candidates, 'Active candidate must come first, then the most-recent Rotated candidate.');
    }

    public function test_a_rotated_secret_outside_the_overlap_window_is_excluded(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $original = 'original-secret-'.Str::random(24);
        $replacement = 'replacement-secret-'.Str::random(24);

        $credential = $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::WebhookSigningSecret, $original));
        $rotated = $this->runWithFirmContext($firm, fn () => $this->credentialService()->rotate($connection, $credential, $replacement));

        // Backdate rotated_at on the OLD (now Rotated) row past the
        // default 24h overlap window.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('id', $credential->id)
            ->update(['rotated_at' => now()->subHours(25)]));

        $resolved = new ResolvedWebhookConnection($firm->id, $connection->id, $connection->integration_provider_id, 'test');
        $candidates = $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved);

        $this->assertSame([$replacement], $candidates);
    }

    public function test_a_revoked_only_credential_with_no_active_row_yields_zero_candidates(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $plaintext = 'about-to-be-revoked-'.Str::random(24);
        $credential = $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::WebhookSigningSecret, $plaintext));
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->revoke($connection, $credential, 'test revoke'));

        $resolved = new ResolvedWebhookConnection($firm->id, $connection->id, $connection->integration_provider_id, 'test');

        $this->assertSame(
            [],
            $this->resolver()->activeAndPreviousWebhookSecretsFor($resolved),
            'revoke() never sets rotated_at, so a revoked-only credential must not satisfy the Rotated-within-window candidate query either.'
        );
    }

    public function test_a_different_firms_connection_never_yields_this_firms_secret(): void
    {
        [$firmA, $connectionA] = $this->connectionForFirm();
        [$firmB, $connectionB] = $this->connectionForFirm();

        $secretA = 'firm-a-secret-'.Str::random(24);
        $secretB = 'firm-b-secret-'.Str::random(24);
        $this->runWithFirmContext($firmA, fn () => $this->credentialService()->store($connectionA, CredentialType::WebhookSigningSecret, $secretA));
        $this->runWithFirmContext($firmB, fn () => $this->credentialService()->store($connectionB, CredentialType::WebhookSigningSecret, $secretB));

        $resolvedA = new ResolvedWebhookConnection($firmA->id, $connectionA->id, $connectionA->integration_provider_id, 'test');
        $candidatesA = $this->resolver()->activeAndPreviousWebhookSecretsFor($resolvedA);

        $this->assertSame([$secretA], $candidatesA);
        $this->assertNotContains($secretB, $candidatesA);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function resolver(): WebhookConnectionResolverService
    {
        return new WebhookConnectionResolverService(
            $this->credentialService(),
            new EmailBodyEncryptionService(new EncryptionKeyService()),
            new TenantContextService(),
        );
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function connectionForFirm(ConnectionStatus $status = ConnectionStatus::Active): array
    {
        $firm = Firm::factory()->create();
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $this->encryptionKeyIds[$firm->id] = $key->id;

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value]);

        return [$firm, $connection];
    }

    private function enableRouting(FirmIntegration $connection): string
    {
        $tokenHash = hash('sha256', $raw = Str::random(43));

        $this->runWithFirmContext($connection->firm_id, function () use ($connection, $tokenHash) {
            IntegrationWebhookRoutingIndex::query()->create([
                'firm_id' => $connection->firm_id,
                'firm_integration_id' => $connection->id,
                'integration_provider_id' => $connection->integration_provider_id,
                'webhook_routing_token_hash' => $tokenHash,
            ]);
        });

        return $raw;
    }
}
