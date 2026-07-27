<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationCredentialServiceFindActiveCredentialTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5.2).
 * findActiveCredential() is the ONE place `credential_type` appears in
 * any query against `integration_credentials` in this checkpoint — an
 * ordinary post-RLS narrowing WHERE clause, never an RLS policy
 * predicate (see CredentialTypeNeverInPolicyRegressionTest for the
 * catalog-level proof of that separate claim).
 */
final class IntegrationCredentialServiceFindActiveCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_credential_of_the_requested_type_exists(): void
    {
        [$firm, $connection] = $this->connectionForFirm();

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret));

        $this->assertNull($result);
    }

    public function test_returns_the_active_credential_of_the_requested_type(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::WebhookSigningSecret, 'secret-'.Str::random(24)));

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret));

        $this->assertNotNull($result);
        $this->assertSame($credential->id, $result->id);
    }

    public function test_ignores_a_credential_of_a_different_type(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::ApiKey, 'api-key-'.Str::random(24)));

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret));

        $this->assertNull($result);
    }

    public function test_ignores_a_rotated_credential_of_the_same_type(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $original = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::WebhookSigningSecret, 'original-'.Str::random(24)));
        $replacement = $this->runWithFirmContext($firm, fn () => $this->service()->rotate($connection, $original, 'replacement-'.Str::random(24)));

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret));

        $this->assertNotNull($result);
        $this->assertSame($replacement->id, $result->id, 'Only the currently-Active row may be returned, never the now-Rotated original.');
    }

    public function test_ignores_a_revoked_credential_of_the_same_type(): void
    {
        [$firm, $connection] = $this->connectionForFirm();
        $credential = $this->runWithFirmContext($firm, fn () => $this->service()->store($connection, CredentialType::WebhookSigningSecret, 'secret-'.Str::random(24)));
        $this->runWithFirmContext($firm, fn () => $this->service()->revoke($connection, $credential, 'revoked for test'));

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret));

        $this->assertNull($result);
    }

    public function test_throws_when_no_tenant_context_is_active(): void
    {
        [, $connection] = $this->connectionForFirm();

        (new TenantContextService)->clearFirmContext();

        $this->expectException(RuntimeException::class);

        $this->service()->findActiveCredential($connection, CredentialType::WebhookSigningSecret);
    }

    public function test_throws_when_the_active_context_belongs_to_a_different_firm(): void
    {
        [, $connectionA] = $this->connectionForFirm();
        [$firmB] = $this->connectionForFirm();

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firmB, fn () => $this->service()->findActiveCredential($connectionA, CredentialType::WebhookSigningSecret));
    }

    public function test_never_returns_a_different_connections_credential_even_of_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        unset($key);

        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, fn () => $this->service()->store($connectionOne, CredentialType::WebhookSigningSecret, 'conn-one-secret-'.Str::random(24)));

        $result = $this->runWithFirmContext($firm, fn () => $this->service()->findActiveCredential($connectionTwo, CredentialType::WebhookSigningSecret));

        $this->assertNull($result);
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

        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        return [$firm, $connection];
    }
}
