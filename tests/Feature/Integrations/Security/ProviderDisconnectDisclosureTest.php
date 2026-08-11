<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Security;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Support\ProviderDisconnectDisclosure;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProviderDisconnectDisclosureTest — Mission 1C, section 18. Proves
 * the one concrete gap Mission 1B's own Microsoft365Provider docblock
 * flagged and left open ("the disconnect-confirmation UI should
 * disclose...") is now real copy, not silent.
 */
class ProviderDisconnectDisclosureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * End-to-end runtime proof: a real Microsoft 365-backed
     * FirmIntegration's own providerKey() (what the disconnect
     * actions actually call at runtime) correctly resolves to
     * ProviderKey::Microsoft365 and therefore a real disclosure —
     * proves the Closure's actual runtime input is correct, not just
     * the decision function in isolation.
     */
    private function providerFor(ProviderKey $key): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', $key->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => $key->value]);
    }

    public function test_a_real_microsoft_365_connection_resolves_to_the_disclosure(): void
    {
        $firm = Firm::factory()->activated()->create();
        $provider = $this->providerFor(ProviderKey::Microsoft365);
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->create(['integration_provider_id' => $provider->id]));

        $disclosure = $this->runWithFirmContext($firm, fn () => ProviderDisconnectDisclosure::forProvider($connection->providerKey()));

        $this->assertNotNull($disclosure);
        $this->assertStringContainsString('myaccount.microsoft.com', $disclosure);
    }

    public function test_a_real_plaid_connection_resolves_to_no_disclosure(): void
    {
        $firm = Firm::factory()->activated()->create();
        $provider = $this->providerFor(ProviderKey::Plaid);
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->create(['integration_provider_id' => $provider->id]));

        $disclosure = $this->runWithFirmContext($firm, fn () => ProviderDisconnectDisclosure::forProvider($connection->providerKey()));

        $this->assertNull($disclosure);
    }

    public function test_microsoft_365_gets_a_real_revocation_disclosure(): void
    {
        $disclosure = ProviderDisconnectDisclosure::forProvider(ProviderKey::Microsoft365);

        $this->assertNotNull($disclosure);
        $this->assertStringContainsString('does not support remote revocation', $disclosure);
        $this->assertStringContainsString('myaccount.microsoft.com', $disclosure);
        $this->assertStringContainsString('Entra admin center', $disclosure);
    }

    public function test_other_providers_get_no_disclosure(): void
    {
        $this->assertNull(ProviderDisconnectDisclosure::forProvider(ProviderKey::GoogleWorkspace));
        $this->assertNull(ProviderDisconnectDisclosure::forProvider(ProviderKey::Plaid));
        $this->assertNull(ProviderDisconnectDisclosure::forProvider(ProviderKey::Test));
    }

    public function test_null_provider_key_gets_no_disclosure(): void
    {
        $this->assertNull(ProviderDisconnectDisclosure::forProvider(null));
    }
}
