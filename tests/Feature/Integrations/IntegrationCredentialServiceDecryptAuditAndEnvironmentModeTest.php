<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationCredentialServiceDecryptAuditAndEnvironmentModeTest —
 * Checkpoint 1 (FirmsVault Live Integrations). Covers two of the
 * checkpoint's OAuth/credential security fixes
 * (checkpoint1-design-oauth-security-review.md §6;
 * checkpoint1-security-review.md Findings 3 and 6):
 *
 *  1. decryptForOperation() writes an `integration_credential.decrypted`
 *     timeline event with the expected non-secret fields, and rejects an
 *     operationId/reason that is too long or looks high-entropy/token-
 *     shaped (assertSafeAuditLabel()).
 *  2. `credential_environment_mode` tamper-evidence: stored via a
 *     dedicated typed column (never inside masked_display_metadata),
 *     and decryptForOperation()'s mode-consistency check against
 *     ProviderEnvironmentResolver::modeFor() — rejecting a mismatch,
 *     gracefully allowing an untagged credential or a provider with no
 *     `provider_environments` entry at all (the TestProvider case).
 */
final class IntegrationCredentialServiceDecryptAuditAndEnvironmentModeTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Decrypt-audit event
    // ------------------------------------------------------------

    public function test_decrypt_for_operation_writes_a_credential_decrypted_timeline_event_with_expected_fields(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'test operation label',
            'test reason label',
        ));

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_credential.decrypted')
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($connection->id, $event->metadata_json['firm_integration_id']);
        $this->assertSame($credential->id, $event->metadata_json['integration_credential_id']);
        $this->assertSame(CredentialType::ApiKey->value, $event->metadata_json['credential_type']);
        $this->assertSame('test operation label', $event->metadata_json['operation_id']);
        $this->assertSame('test reason label', $event->metadata_json['reason']);

        // Never the plaintext or ciphertext.
        $metadataJson = json_encode($event->metadata_json);
        $this->assertStringNotContainsString('plaintext-secret-value', $metadataJson);
    }

    // ------------------------------------------------------------
    // assertSafeAuditLabel() — negative cases
    // ------------------------------------------------------------

    public function test_decrypt_for_operation_rejects_an_operation_id_exceeding_128_characters(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        $tooLong = str_repeat('a ', 65); // 130 chars, space-broken so it never trips the entropy heuristic.

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            $tooLong,
            'a valid reason',
        ));
    }

    public function test_decrypt_for_operation_rejects_a_reason_exceeding_128_characters(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        $tooLong = str_repeat('b ', 65);

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'a valid operation id',
            $tooLong,
        ));
    }

    public function test_decrypt_for_operation_rejects_an_operation_id_containing_a_20_char_high_entropy_run(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        // A contiguous 24-character run drawn from [A-Za-z0-9+/=_-] —
        // exactly the shape assertSafeAuditLabel()'s heuristic rejects
        // (an accidentally-passed token/secret).
        $highEntropy = 'operation: aGVsbG9Xb3JsZEZvb0Jhcg== token';

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            $highEntropy,
            'a valid reason',
        ));
    }

    public function test_decrypt_for_operation_rejects_a_reason_containing_a_20_char_high_entropy_run(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        $highEntropy = 'reason: aGVsbG9Xb3JsZEZvb0Jhcg== token';

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'a valid operation id',
            $highEntropy,
        ));
    }

    // ------------------------------------------------------------
    // assertSafeAuditLabel() — positive cases
    // ------------------------------------------------------------

    public function test_decrypt_for_operation_accepts_a_short_deterministic_operation_id_and_reason(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        // Matches the real production convention (space-separated, never
        // a contiguous hyphen/digit run) already established in
        // ProviderConnectionService/WebhookConnectionResolverService.
        $operationId = 'oauth refresh: connection '.$connection->id.' at 1234567890';

        $plaintext = $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            $operationId,
            'oauth_token_refresh',
        ));

        $this->assertSame('plaintext-secret-value', $plaintext);
    }

    public function test_decrypt_for_operation_accepts_an_operation_id_at_exactly_128_characters(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'plaintext-secret-value'));

        // Exactly 128 chars: 'a ' repeated 64 times — alternating
        // char/space so no contiguous high-entropy run ever forms.
        $exactly128 = str_repeat('a ', 64);
        $this->assertSame(128, mb_strlen($exactly128));

        $plaintext = $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            $exactly128,
            'valid reason',
        ));

        $this->assertSame('plaintext-secret-value', $plaintext);
    }

    // ------------------------------------------------------------
    // credential_environment_mode tamper-evidence (Finding 3)
    // ------------------------------------------------------------

    public function test_store_with_an_environment_mode_sets_the_dedicated_column_and_never_the_metadata_bag(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
            metadata: ['display_label' => 'a non-secret label'],
            environmentMode: 'sandbox',
        ));

        $this->assertSame('sandbox', $credential->credential_environment_mode);

        $maskedMetadata = $credential->masked_display_metadata;
        $this->assertIsArray($maskedMetadata);
        $this->assertArrayNotHasKey('mode', $maskedMetadata);
        $this->assertArrayNotHasKey('environment_mode', $maskedMetadata);
        $this->assertArrayNotHasKey('credential_environment_mode', $maskedMetadata);
        $this->assertSame('a non-secret label', $maskedMetadata['display_label']);
    }

    public function test_store_rejects_an_invalid_environment_mode_value(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
            environmentMode: 'not-a-real-mode',
        ));
    }

    public function test_decrypt_for_operation_rejects_when_the_credentials_tagged_mode_does_not_match_the_connections_configured_mode(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'live',
            'sandbox_base_url' => 'https://sandbox-api.example.test',
            'live_base_url' => 'https://live-api.example.test',
        ]]);

        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
            environmentMode: 'sandbox',
        ));

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'valid operation id',
            'valid reason',
        ));
    }

    public function test_decrypt_for_operation_allows_a_matching_mode(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'sandbox',
            'sandbox_base_url' => 'https://sandbox-api.example.test',
            'live_base_url' => 'https://live-api.example.test',
        ]]);

        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
            environmentMode: 'sandbox',
        ));

        $plaintext = $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'valid operation id',
            'valid reason',
        ));

        $this->assertSame('plaintext-secret-value', $plaintext);
    }

    public function test_decrypt_for_operation_gracefully_allows_an_untagged_credential_even_when_the_provider_has_a_configured_mode(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'live',
            'sandbox_base_url' => 'https://sandbox-api.example.test',
            'live_base_url' => 'https://live-api.example.test',
        ]]);

        // No environmentMode passed — untagged (null) credential.
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
        ));

        $this->assertNull($credential->credential_environment_mode);

        $plaintext = $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'valid operation id',
            'valid reason',
        ));

        $this->assertSame('plaintext-secret-value', $plaintext);
    }

    public function test_decrypt_for_operation_gracefully_allows_a_tagged_credential_when_the_provider_has_no_configured_environment_at_all(): void
    {
        // The genuine TestProvider case: `test` is never present in
        // `integrations.provider_environments` by default — this must
        // never throw for any existing TestProvider-backed credential.
        // Checkpoint 2 update: the array as a whole is no longer empty
        // (a real `microsoft365` entry now exists) — narrowed to assert
        // specifically that the `test` provider key is absent, which is
        // this test's actual intent.
        [$firm, $connection] = $this->connectionForFirm();

        $this->assertArrayNotHasKey(ProviderKey::Test->value, config('integrations.provider_environments'));

        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'plaintext-secret-value',
            environmentMode: 'sandbox',
        ));

        $plaintext = $this->runWithFirmContext($firm, fn () => $this->service()->decryptForOperation(
            $connection->fresh(),
            $credential,
            'valid operation id',
            'valid reason',
        ));

        $this->assertSame('plaintext-secret-value', $plaintext);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function service(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function connectionForFirm(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);

        $connection = FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create(['external_account_id' => null]);

        return [$firm, $connection];
    }
}
