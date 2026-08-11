<?php

namespace Tests\Feature\Security\WebAuthn;

use App\Filament\MultiFactor\WebAuthn\Actions\DisableWebAuthnCredentialAction;
use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use App\Services\Security\StepUpAuthenticationService;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * DisableWebAuthnCredentialActionTest — Mission 1B (Extreme Security
 * Hardening), sections 7 & 9. Proves the action is genuinely wired
 * onto the canonical StepUpAuthentication helper: no recent
 * verification means the modal still requires a password field, and a
 * recent verification (e.g. from confirming an earlier protected
 * action in the same session) lets it proceed without re-prompting.
 *
 * Resolves the schema closure directly via reflection rather than
 * through Filament's getSchema(Schema $schema), which requires a real
 * mounted Livewire schema container this test doesn't construct — the
 * same "one layer below full rendering" boundary this codebase's other
 * WebAuthn tests already use (see WebAuthnAuthenticationTest).
 */
class DisableWebAuthnCredentialActionTest extends TestCase
{
    use RefreshDatabase;

    private function resolveSchemaComponents(Action $action): array
    {
        $property = new ReflectionProperty(Action::class, 'schema');
        $property->setAccessible(true);
        $schema = $property->getValue($action);

        return $schema();
    }

    public function test_requires_the_password_field_without_a_recent_step_up_verification(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $credential = WebauthnCredential::factory()->create(['platform_admin_id' => $admin->id]);
        $this->actingAs($admin, 'platform_admin');

        $action = DisableWebAuthnCredentialAction::make($credential);

        $this->assertCount(1, $this->resolveSchemaComponents($action));
    }

    public function test_omits_the_password_field_with_a_recent_step_up_verification(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $credential = WebauthnCredential::factory()->create(['platform_admin_id' => $admin->id]);
        $this->actingAs($admin, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $action = DisableWebAuthnCredentialAction::make($credential);

        $this->assertCount(0, $this->resolveSchemaComponents($action));
    }
}
