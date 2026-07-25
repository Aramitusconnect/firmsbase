<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminMfaResetService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformAdminMfaResetServiceTest — MFA design proposal §8. Also
 * proves §6's explicit finding: an MFA reset must remain unconditionally
 * available even when the target is the sole active SuperAdmin — this
 * service (unlike TogglePlatformAdminActiveStatusAction/
 * RevokePlatformAdminRoleAction) never calls
 * PlatformRoleService::wouldLeaveNoActiveSuperAdmin() at all.
 */
class PlatformAdminMfaResetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_mfa_state_and_stamps_reset_at(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $target = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['hash-one'],
            'two_factor_confirmed_at' => now(),
        ]);

        app(PlatformAdminMfaResetService::class)->reset($actor, $target, 'lost device');

        $target->refresh();

        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertNotNull($target->two_factor_reset_at);
    }

    public function test_reset_writes_an_audit_event_attributed_to_the_acting_admin_with_reason_and_target_in_metadata(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        app(PlatformAdminMfaResetService::class)->reset($actor, $target, 'lost device and recovery codes');

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'mfa_reset_by_admin')
                ->where('actor_id', $actor->id)
                ->first()
        );

        $this->assertNotNull($row);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame('platform_admin_mfa', $row->category);
        $this->assertNull($row->firm_id);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($target->id, $metadata['target_platform_admin_id']);
        $this->assertSame($target->uuid, $metadata['target_platform_admin_uuid']);
        $this->assertSame('lost device and recovery codes', $metadata['reason']);
    }

    public function test_reset_is_not_blocked_when_target_is_the_sole_active_super_admin(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $soleSuperAdmin = PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        app(PlatformRoleService::class)->grant($soleSuperAdmin, PlatformRoleCode::SuperAdmin);

        // Confirm the fixture really is "sole active SuperAdmin" before
        // asserting the reset ignores it.
        $this->assertTrue(app(PlatformRoleService::class)->wouldLeaveNoActiveSuperAdmin($soleSuperAdmin));

        app(PlatformAdminMfaResetService::class)->reset($actor, $soleSuperAdmin, 'emergency');

        $soleSuperAdmin->refresh();
        $this->assertNull($soleSuperAdmin->two_factor_secret);
        $this->assertNotNull($soleSuperAdmin->two_factor_reset_at);

        // The role itself must be completely untouched by a reset.
        $this->assertTrue(app(PlatformRoleService::class)->hasRole($soleSuperAdmin, PlatformRoleCode::SuperAdmin));
        $this->assertTrue($soleSuperAdmin->is_active);
    }

    public function test_reset_is_idempotent_when_target_was_never_enrolled(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $target = PlatformAdmin::factory()->create([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);

        app(PlatformAdminMfaResetService::class)->reset($actor, $target, 'sole superadmin emergency, was already unenrolled');

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNotNull($target->two_factor_reset_at);
    }
}
