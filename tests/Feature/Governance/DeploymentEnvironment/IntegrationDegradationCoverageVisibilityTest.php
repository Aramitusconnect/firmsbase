<?php

namespace Tests\Feature\Governance\DeploymentEnvironment;

use App\Enums\AiProvider;
use App\Enums\ConsentChannel;
use App\Enums\IntegrationType;
use App\Services\IntegrationDegradationRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IntegrationDegradationCoverageVisibilityTest — proves
 * IntegrationDegradationRegistryService::everyIntegrationHasADeclaredMode()
 * is scoped ONLY to the current IntegrationType enum, and that AI
 * provider / SMS / WhatsApp coverage is NOT silently treated as
 * complete just because it exists outside that enum. Does not modify
 * IntegrationType or the registry service — read-only visibility test.
 */
class IntegrationDegradationCoverageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_integration_type_cases_are_explicitly_known(): void
    {
        $values = array_map(fn ($case) => $case->value, IntegrationType::cases());
        sort($values);

        $this->assertSame(['email_provider', 'stripe', 'telemetry', 'virus_scanning'], $values);
    }

    public function test_every_integration_has_a_declared_mode_is_scoped_only_to_integration_type(): void
    {
        // The seeded migration declares exactly the 4 current
        // IntegrationType cases, so this reports true — but that
        // "true" is scoped ONLY to IntegrationType, never to AI
        // providers or SMS/WhatsApp, which live in different enums
        // entirely.
        $this->assertTrue(app(IntegrationDegradationRegistryService::class)->everyIntegrationHasADeclaredMode());

        $declaredTypeValues = array_keys(app(IntegrationDegradationRegistryService::class)->allDeclarations());
        sort($declaredTypeValues);
        $enumValues = array_map(fn ($case) => $case->value, IntegrationType::cases());
        sort($enumValues);

        $this->assertSame($enumValues, $declaredTypeValues, 'The declared set must exactly match IntegrationType::cases(), proving the "complete" signal is scoped only to this enum.');
    }

    public function test_ai_provider_dependency_exists_outside_integration_type_and_has_no_declared_mode(): void
    {
        $integrationTypeValues = array_map(fn ($case) => $case->value, IntegrationType::cases());

        $this->assertNotEmpty(AiProvider::cases(), 'AiProvider must be a real, non-empty dependency for this test to be meaningful.');

        foreach (AiProvider::cases() as $provider) {
            $this->assertNotContains($provider->value, $integrationTypeValues, "AiProvider::{$provider->name} must not be silently treated as covered by IntegrationType.");
        }

        // Structural proof: IntegrationDegradationRegistryService::behaviorFor()
        // only accepts an IntegrationType instance. There is no
        // IntegrationType case an AiProvider value could ever be
        // passed as, so this service can never even be asked about AI
        // providers — the gap is structurally invisible to it, not
        // merely an unfilled row.
        $behaviorForParameterType = (new \ReflectionMethod(IntegrationDegradationRegistryService::class, 'behaviorFor'))
            ->getParameters()[0]->getType()->getName();
        $this->assertSame(IntegrationType::class, $behaviorForParameterType);
    }

    public function test_sms_and_whatsapp_dependencies_exist_outside_integration_type_and_have_no_declared_mode(): void
    {
        $integrationTypeValues = array_map(fn ($case) => $case->value, IntegrationType::cases());

        $this->assertContains('sms', array_map(fn ($case) => $case->value, ConsentChannel::cases()));
        $this->assertContains('whatsapp', array_map(fn ($case) => $case->value, ConsentChannel::cases()));

        $this->assertNotContains('sms', $integrationTypeValues);
        $this->assertNotContains('whatsapp', $integrationTypeValues);
    }

    public function test_coverage_is_not_silently_reported_complete_for_undeclared_dependencies(): void
    {
        // everyIntegrationHasADeclaredMode() being true tells a
        // reader NOTHING about AI/SMS/WhatsApp coverage — it can only
        // ever be true or false with respect to IntegrationType. This
        // test documents that boundary explicitly so a future reader
        // does not misread "true" as "every real dependency is
        // covered."
        $complete = app(IntegrationDegradationRegistryService::class)->everyIntegrationHasADeclaredMode();

        $this->assertTrue($complete);
        $this->assertCount(4, IntegrationType::cases(), 'If this count ever changes, re-verify whether AI/SMS/WhatsApp were added here directly (they should not be, per Section 29 scope) or via a dedicated future expansion.');
    }
}
