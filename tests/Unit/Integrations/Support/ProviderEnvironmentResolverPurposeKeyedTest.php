<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderEnvironmentMisconfiguredException;
use App\Integrations\Support\ProviderEnvironmentResolver;
use Tests\TestCase;

/**
 * ProviderEnvironmentResolverPurposeKeyedTest — Checkpoint 2 (FirmsVault
 * Live Integrations) test-writing pass. Proves the Checkpoint 2 widening
 * of ProviderEnvironmentResolver::baseUrlFor()/modeFor()/assertUrlAllowedFor()
 * from a singular sandbox_base_url/live_base_url string to a
 * purpose-keyed sandbox_base_urls/live_base_urls array
 * (checkpoint2-combined-design.md §2 P-8), and — the specific required
 * fix — checkpoint2-security-review.md Finding 4: requesting an
 * unconfigured, non-default purpose must throw
 * ProviderEnvironmentMisconfiguredException and must NEVER silently fall
 * back to a 'default' key, even when one happens to be present alongside
 * purpose-specific keys in the same config block.
 *
 * Pure config/logic class — no network call, no DB write — so no
 * Http::fake() is needed anywhere in this file.
 */
final class ProviderEnvironmentResolverPurposeKeyedTest extends TestCase
{
    // ------------------------------------------------------------
    // Purpose-keyed resolution (Microsoft-shaped: identity + graph)
    // ------------------------------------------------------------

    public function test_base_url_for_resolves_the_correct_url_per_purpose_in_sandbox_mode(): void
    {
        $this->configureDualHostProvider('sandbox');
        $resolver = new ProviderEnvironmentResolver;

        $this->assertSame(
            'https://login.microsoftonline.sandbox.test',
            $resolver->baseUrlFor(ProviderKey::Microsoft365, 'identity'),
        );
        $this->assertSame(
            'https://graph.microsoft.sandbox.test',
            $resolver->baseUrlFor(ProviderKey::Microsoft365, 'graph'),
        );
    }

    public function test_base_url_for_resolves_the_correct_url_per_purpose_in_live_mode(): void
    {
        $this->configureDualHostProvider('live');
        $resolver = new ProviderEnvironmentResolver;

        $this->assertSame(
            'https://login.microsoftonline.live.test',
            $resolver->baseUrlFor(ProviderKey::Microsoft365, 'identity'),
        );
        $this->assertSame(
            'https://graph.microsoft.live.test',
            $resolver->baseUrlFor(ProviderKey::Microsoft365, 'graph'),
        );
    }

    public function test_assert_url_allowed_for_validates_a_call_against_the_correct_purpose_specific_host_and_rejects_the_other_purposes_host(): void
    {
        $this->configureDualHostProvider('sandbox');
        $resolver = new ProviderEnvironmentResolver;

        // A genuine identity-host URL validates fine against the
        // 'identity' purpose...
        $resolver->assertUrlAllowedFor(ProviderKey::Microsoft365, 'https://login.microsoftonline.sandbox.test/organizations/oauth2/v2.0/token', 'identity');
        $this->addToAssertionCount(1);

        // ...but the SAME URL must be rejected when checked against the
        // 'graph' purpose — the two hosts are genuinely distinct and the
        // purpose dimension must not be ignored by the host/port/path
        // guard.
        $this->expectException(ProviderEnvironmentMisconfiguredException::class);
        $resolver->assertUrlAllowedFor(ProviderKey::Microsoft365, 'https://login.microsoftonline.sandbox.test/organizations/oauth2/v2.0/token', 'graph');
    }

    // ------------------------------------------------------------
    // Finding 4 (P1, required): fail-closed on an unconfigured purpose —
    // never a silent fallback to 'default', even when 'default' is
    // present alongside purpose-specific keys in the same config block.
    // ------------------------------------------------------------

    public function test_requesting_an_unconfigured_purpose_throws_rather_than_resolving_to_any_other_key(): void
    {
        $this->configureDualHostProvider('sandbox');
        $resolver = new ProviderEnvironmentResolver;

        $this->expectException(ProviderEnvironmentMisconfiguredException::class);

        $resolver->baseUrlFor(ProviderKey::Microsoft365, 'nonexistent');
    }

    /**
     * The exact scenario Finding 4 describes: a config block that
     * DELIBERATELY carries a 'default' key ALONGSIDE purpose-specific
     * ('identity'/'graph') keys — an authoring mistake the resolver must
     * still fail closed against. Requesting a THIRD, unconfigured
     * purpose must throw, never silently resolve against the 'default'
     * key that happens to also be present.
     */
    public function test_an_unconfigured_purpose_throws_even_when_a_default_key_is_present_alongside_purpose_specific_keys(): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
            'mode' => 'sandbox',
            'sandbox_base_urls' => [
                // Deliberately mixing 'default' with purpose-specific
                // keys in the SAME block — the operational convention
                // says this should never happen, but the resolver's own
                // fail-closed guarantee must not depend on every future
                // config author following that convention correctly.
                'default' => 'https://should-never-be-used.example.test',
                'identity' => 'https://login.microsoftonline.sandbox.test',
                'graph' => 'https://graph.microsoft.sandbox.test',
            ],
            'live_base_urls' => [
                'default' => 'https://should-never-be-used.example.test',
                'identity' => 'https://login.microsoftonline.live.test',
                'graph' => 'https://graph.microsoft.live.test',
            ],
        ]]);

        $resolver = new ProviderEnvironmentResolver;

        try {
            $resolver->baseUrlFor(ProviderKey::Microsoft365, 'calendar');
            $this->fail('Expected a ProviderEnvironmentMisconfiguredException — an unconfigured purpose must never silently resolve to the "default" key.');
        } catch (ProviderEnvironmentMisconfiguredException $e) {
            // Expected — and confirm the message never claims to have
            // resolved a URL at all.
            $this->assertStringNotContainsString('should-never-be-used.example.test', $e->getMessage());
        }

        // Sanity check: the purpose-specific keys the block DOES define
        // still resolve correctly — this failure is specific to the
        // unconfigured purpose, not a general breakage of the block.
        $this->assertSame('https://login.microsoftonline.sandbox.test', $resolver->baseUrlFor(ProviderKey::Microsoft365, 'identity'));
        $this->assertSame('https://graph.microsoft.sandbox.test', $resolver->baseUrlFor(ProviderKey::Microsoft365, 'graph'));
    }

    public function test_assert_url_allowed_for_also_fails_closed_for_an_unconfigured_purpose_never_validating_against_a_present_default_key(): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
            'mode' => 'sandbox',
            'sandbox_base_urls' => [
                'default' => 'https://should-never-be-used.example.test',
                'identity' => 'https://login.microsoftonline.sandbox.test',
            ],
            'live_base_urls' => [
                'default' => 'https://should-never-be-used.example.test',
                'identity' => 'https://login.microsoftonline.live.test',
            ],
        ]]);

        $resolver = new ProviderEnvironmentResolver;

        $this->expectException(ProviderEnvironmentMisconfiguredException::class);

        // Even a URL that WOULD match the present 'default' key must
        // still be rejected when the caller asks for a different,
        // unconfigured purpose — assertUrlAllowedFor() must never
        // silently validate against a key nobody asked for.
        $resolver->assertUrlAllowedFor(ProviderKey::Microsoft365, 'https://should-never-be-used.example.test/anything', 'webhooks');
    }

    // ------------------------------------------------------------
    // Existing single-host ('default'-only) provider shape — must keep
    // working byte-for-byte unchanged (e.g. Plaid-shaped providers).
    // ------------------------------------------------------------

    public function test_a_default_only_single_host_provider_shape_still_resolves_correctly_when_purpose_is_omitted(): void
    {
        $this->configureSingleHostProvider();
        $resolver = new ProviderEnvironmentResolver;

        $this->assertSame('https://sandbox-api.example.test', $resolver->baseUrlFor(ProviderKey::Test));
        $this->assertSame('sandbox', $resolver->modeFor(ProviderKey::Test));
    }

    public function test_a_default_only_single_host_provider_shape_still_validates_urls_correctly_when_purpose_is_omitted(): void
    {
        $this->configureSingleHostProvider();
        $resolver = new ProviderEnvironmentResolver;

        $resolver->assertUrlAllowedFor(ProviderKey::Test, 'https://sandbox-api.example.test/resource');
        $this->addToAssertionCount(1);

        $this->expectException(ProviderEnvironmentMisconfiguredException::class);
        $resolver->assertUrlAllowedFor(ProviderKey::Test, 'https://not-the-configured-host.example.test/resource');
    }

    public function test_a_default_only_provider_still_throws_for_a_non_default_purpose_it_never_configured(): void
    {
        $this->configureSingleHostProvider();
        $resolver = new ProviderEnvironmentResolver;

        $this->expectException(ProviderEnvironmentMisconfiguredException::class);

        $resolver->baseUrlFor(ProviderKey::Test, 'graph');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function configureDualHostProvider(string $mode): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
            'mode' => $mode,
            'sandbox_base_urls' => [
                'identity' => 'https://login.microsoftonline.sandbox.test',
                'graph' => 'https://graph.microsoft.sandbox.test',
            ],
            'live_base_urls' => [
                'identity' => 'https://login.microsoftonline.live.test',
                'graph' => 'https://graph.microsoft.live.test',
            ],
        ]]);
    }

    private function configureSingleHostProvider(): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'sandbox',
            'sandbox_base_urls' => ['default' => 'https://sandbox-api.example.test'],
            'live_base_urls' => ['default' => 'https://live-api.example.test'],
        ]]);
    }
}
