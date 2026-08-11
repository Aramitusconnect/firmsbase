<?php

declare(strict_types=1);

namespace Tests\Feature\Security\SessionRevocation;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\TogglePlatformAdminActiveStatusAction;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DeactivatePlatformAdminRevokesSessionsTest — Mission 1B (Extreme
 * Security Hardening), sections 11 & 52. Proves
 * TogglePlatformAdminActiveStatusAction's deactivate direction really
 * deletes the target's session rows through the real Livewire action,
 * not merely that SessionRevocationService works in isolation (see
 * SessionRevocationServiceTest for that).
 */
class DeactivatePlatformAdminRevokesSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['session.driver' => 'database']);
    }

    private function seedSessionRowFor(PlatformAdmin $admin, string $sessionId): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $admin->id,
            'payload' => base64_encode(json_encode([Auth::guard('platform_admin')->getName() => $admin->id])),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_deactivating_an_admin_revokes_all_of_their_session_rows(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->seedSessionRowFor($target, 'target-sess-1');
        $this->seedSessionRowFor($target, 'target-sess-2');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(TogglePlatformAdminActiveStatusAction::getDefaultName());
        $test->callMountedAction();

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['target-sess-1', 'target-sess-2'])->count());
    }

    public function test_activating_an_admin_does_not_touch_any_sessions(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create(['is_active' => false]);
        $this->seedSessionRowFor($target, 'target-sess-1');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(TogglePlatformAdminActiveStatusAction::getDefaultName());
        $test->callMountedAction();

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertSame(1, DB::table('sessions')->where('id', 'target-sess-1')->count());
    }

    public function test_a_blocked_deactivation_does_not_revoke_sessions(): void
    {
        // Same "sole SuperAdmin targets themselves" block this
        // action already enforces (PlatformAdminLastSuperAdminProtectionTest)
        // — sessions must survive a deactivation that never actually happened.
        $sole = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($sole, PlatformRoleCode::SuperAdmin);
        $this->actingAs($sole, 'platform_admin');
        $this->seedSessionRowFor($sole, 'sole-sess-1');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $sole->uuid]);
        $test->mountAction(TogglePlatformAdminActiveStatusAction::getDefaultName());
        $test->callMountedAction();

        $sole->refresh();
        $this->assertTrue($sole->is_active);
        $this->assertSame(1, DB::table('sessions')->where('id', 'sole-sess-1')->count());
    }
}
