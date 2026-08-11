<?php

namespace Tests\Feature\Security\StepUp;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Security\StepUpAuthenticationService;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StepUpAuthenticationTest — Mission 1B (Extreme Security Hardening),
 * section 9. Exercises verifyAndMark() directly (the rule's real
 * logic, extracted precisely so it doesn't require mounting a full
 * Filament Livewire schema to test) and schemaFor()'s recent-
 * verification toggle.
 */
class StepUpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_password_fails_and_does_not_mark_verified(): void
    {
        $admin = PlatformAdmin::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($admin, 'platform_admin');

        $failed = null;
        StepUpAuthentication::verifyAndMark('platform_admin', 'wrong-password', function ($message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNotNull($failed);
        $this->assertFalse(app(StepUpAuthenticationService::class)->hasRecentVerification('platform_admin', 5));
    }

    public function test_null_password_fails_and_does_not_mark_verified(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform_admin');

        $failed = null;
        StepUpAuthentication::verifyAndMark('platform_admin', null, function ($message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNotNull($failed);
        $this->assertFalse(app(StepUpAuthenticationService::class)->hasRecentVerification('platform_admin', 5));
    }

    public function test_correct_password_succeeds_and_marks_verified(): void
    {
        $admin = PlatformAdmin::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($admin, 'platform_admin');

        $failed = null;
        StepUpAuthentication::verifyAndMark('platform_admin', 'correct-horse-battery-staple', function ($message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNull($failed);
        $this->assertTrue(app(StepUpAuthenticationService::class)->hasRecentVerification('platform_admin', 5));
    }

    public function test_no_authenticated_user_on_the_guard_fails(): void
    {
        $failed = null;
        StepUpAuthentication::verifyAndMark('platform_admin', 'anything', function ($message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNotNull($failed);
    }

    public function test_verification_on_one_guard_does_not_satisfy_another(): void
    {
        $admin = PlatformAdmin::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($admin, 'platform_admin');

        StepUpAuthentication::verifyAndMark('platform_admin', 'correct-horse-battery-staple', function (): void {});

        $user = User::factory()->create(['password' => bcrypt('another-correct-password')]);
        $this->actingAs($user, 'web');

        $failed = null;
        StepUpAuthentication::verifyAndMark('web', 'wrong-password', function ($message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNotNull($failed);
        $this->assertTrue(app(StepUpAuthenticationService::class)->hasRecentVerification('platform_admin', 5));
        $this->assertFalse(app(StepUpAuthenticationService::class)->hasRecentVerification('web', 5));
    }

    public function test_schema_for_requires_the_password_field_without_recent_verification(): void
    {
        $components = StepUpAuthentication::schemaFor('platform_admin', 5);

        $this->assertCount(1, $components);
        $this->assertInstanceOf(TextInput::class, $components[0]);
        $this->assertSame('stepUpCurrentPassword', $components[0]->getName());
    }

    public function test_schema_for_is_empty_with_a_recent_verification(): void
    {
        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $components = StepUpAuthentication::schemaFor('platform_admin', 5);

        $this->assertCount(0, $components);
    }
}
