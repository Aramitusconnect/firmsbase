<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Enums\IntegrationType;
use App\Integrations\Enums\ProviderKey;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Pure unit test — no framework boot, no database, no factories.
 *
 * Checkpoint 1 registered exactly one provider key ('test'). FirmsVault
 * Live Integrations Checkpoint 2 (Microsoft 365 provider —
 * checkpoint2-combined-design.md §1.1, P-1) added a second, real
 * (non-Test) provider key, 'microsoft365' — the first real provider key
 * ever registered in this enum. Checkpoint 3 (Google Workspace provider —
 * checkpoint3-combined-design.md §1.1) added a third, 'googleworkspace'
 * — a full compound provider name, lowercase, zero separator, an exact
 * structural match to 'microsoft365'. This test proves the enum's shape
 * is stable/immutable/lowercase and — critically — that it shares no
 * case name or backed value with the existing, unrelated App\Enums\
 * IntegrationType enum (a Phase 16 enum for Stripe/EmailProvider/
 * VirusScanning/Telemetry degradation modes). A collision here would
 * mean code that branches on one enum's value could silently misinterpret
 * a value from the other.
 */
final class ProviderKeyTest extends TestCase
{
    public function test_it_is_a_string_backed_enum(): void
    {
        $reflection = new ReflectionEnum(ProviderKey::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }

    /**
     * RENAMED AGAIN (FirmsVault Live Integrations Checkpoint 4, "Plaid
     * financial evidence add-on"): was
     * test_it_has_exactly_the_expected_three_cases(), asserting a count
     * of 3. ProviderKey now registers a fourth case, Plaid, so the old
     * "three cases" name became actively misleading (not merely
     * imprecise) — renamed to reflect the new, exact expected count.
     * Mirrors this codebase's own RlsForceRollout convention of encoding
     * the exact expected count in a test's name and renaming it whenever
     * that count legitimately changes.
     */
    public function test_it_has_exactly_the_expected_four_cases(): void
    {
        $cases = ProviderKey::cases();

        $this->assertCount(4, $cases, 'ProviderKey must register exactly four cases as of FirmsVault Live Integrations Checkpoint 4.');
        $this->assertSame('Test', $cases[0]->name);
        $this->assertSame(ProviderKey::Test, $cases[0]);
        $this->assertSame('Microsoft365', $cases[1]->name);
        $this->assertSame(ProviderKey::Microsoft365, $cases[1]);
        $this->assertSame('GoogleWorkspace', $cases[2]->name);
        $this->assertSame(ProviderKey::GoogleWorkspace, $cases[2]);
        $this->assertSame('googleworkspace', $cases[2]->value);
        $this->assertSame('Plaid', $cases[3]->name);
        $this->assertSame(ProviderKey::Plaid, $cases[3]);
        $this->assertSame('plaid', $cases[3]->value);
    }

    public function test_backed_value_is_lowercase(): void
    {
        foreach (ProviderKey::cases() as $case) {
            $this->assertSame(
                strtolower($case->value),
                $case->value,
                "ProviderKey::{$case->name}'s backed value '{$case->value}' must be lowercase."
            );
        }
    }

    public function test_backed_value_is_stable_and_matches_documented_key(): void
    {
        // Locks the exact string, not just "is a string" — a silent
        // rename here would be a breaking change to anything that has
        // persisted or compared against 'test'.
        $this->assertSame('test', ProviderKey::Test->value);
    }

    public function test_no_case_name_collides_with_integration_type(): void
    {
        $providerKeyNames = array_map(static fn (ProviderKey $case): string => $case->name, ProviderKey::cases());
        $integrationTypeNames = array_map(static fn (IntegrationType $case): string => $case->name, IntegrationType::cases());

        $this->assertEmpty(
            array_intersect($providerKeyNames, $integrationTypeNames),
            'ProviderKey must not share any case name with the unrelated App\\Enums\\IntegrationType enum.'
        );
    }

    public function test_no_backed_value_collides_with_integration_type(): void
    {
        $providerKeyValues = array_map(static fn (ProviderKey $case): string => $case->value, ProviderKey::cases());
        $integrationTypeValues = array_map(static fn (IntegrationType $case): string => $case->value, IntegrationType::cases());

        // Read directly off the real IntegrationType enum (not a
        // hand-copied literal list) so this test cannot drift from the
        // production enum if it is ever extended.
        $this->assertNotEmpty($integrationTypeValues, 'Sanity check: IntegrationType must not be empty for this comparison to be meaningful.');

        $this->assertEmpty(
            array_intersect($providerKeyValues, $integrationTypeValues),
            'ProviderKey must not share any backed value with the unrelated App\\Enums\\IntegrationType enum. '
                .'IntegrationType values: '.implode(', ', $integrationTypeValues)
        );
    }

    public function test_integration_type_still_has_its_known_four_cases(): void
    {
        // Guards the assumption behind the two collision tests above:
        // if IntegrationType's shape ever changes underneath this test,
        // we want a loud, specific failure here rather than a silently
        // weaker collision check.
        $values = array_map(static fn (IntegrationType $case): string => $case->value, IntegrationType::cases());

        sort($values);

        $this->assertSame(
            ['email_provider', 'stripe', 'telemetry', 'virus_scanning'],
            $values
        );
    }
}
