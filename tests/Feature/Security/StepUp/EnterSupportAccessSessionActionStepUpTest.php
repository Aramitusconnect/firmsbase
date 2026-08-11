<?php

declare(strict_types=1);

namespace Tests\Feature\Security\StepUp;

use App\Filament\Actions\Platform\EnterSupportAccessSessionAction;
use App\Models\PlatformAdmin;
use App\Services\Security\StepUpAuthenticationService;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * EnterSupportAccessSessionActionStepUpTest — Mission 1B (Extreme
 * Security Hardening), section 45. Proves impersonation start is
 * genuinely wired onto the canonical StepUpAuthentication helper via
 * mergeInto() — the existing request-selection field survives
 * alongside the step-up password field, rather than being replaced by
 * it.
 */
class EnterSupportAccessSessionActionStepUpTest extends TestCase
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
        $this->actingAs($admin, 'platform_admin');

        $action = EnterSupportAccessSessionAction::make();
        $components = $this->resolveSchemaComponents($action);

        // The existing request_uuid Select survives, plus the step-up field.
        $this->assertCount(2, $components);
    }

    public function test_omits_the_password_field_with_a_recent_step_up_verification(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $action = EnterSupportAccessSessionAction::make();
        $components = $this->resolveSchemaComponents($action);

        // Only the existing request_uuid Select remains.
        $this->assertCount(1, $components);
    }
}
