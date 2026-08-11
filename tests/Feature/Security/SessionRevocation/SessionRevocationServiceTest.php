<?php

namespace Tests\Feature\Security\SessionRevocation;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Security\SessionRevocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SessionRevocationServiceTest — Mission 1B (Extreme Security
 * Hardening), sections 11 & 52. Seeds real rows into the `sessions`
 * table with genuinely Laravel-formatted payloads (base64(json_encode(
 * [authKey => id, ...]))) — the exact shape SessionGuard itself
 * writes — rather than mocking the decode step, so this proves the
 * service reads the real on-disk format correctly.
 */
class SessionRevocationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function seedSessionRow(string $id, string $guard, int $userId, array $extra = []): void
    {
        $authKey = Auth::guard($guard)->getName();

        $payload = array_merge([$authKey => $userId], $extra);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(json_encode($payload)),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_revokes_every_session_row_for_the_given_user_and_guard(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->seedSessionRow('sess-1', 'platform_admin', $admin->id);
        $this->seedSessionRow('sess-2', 'platform_admin', $admin->id);

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($admin, 'platform_admin');

        $this->assertSame(2, $revoked);
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_does_not_revoke_sessions_belonging_to_a_different_user(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $otherAdmin = PlatformAdmin::factory()->create();
        $this->seedSessionRow('sess-target', 'platform_admin', $admin->id);
        $this->seedSessionRow('sess-other', 'platform_admin', $otherAdmin->id);

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($admin, 'platform_admin');

        $this->assertSame(1, $revoked);
        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-target')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-other')->count());
    }

    public function test_does_not_revoke_a_numerically_colliding_id_on_a_different_guard(): void
    {
        // The exact ambiguity this service exists to avoid: a User and
        // a PlatformAdmin sharing the same numeric primary key must
        // never cross-contaminate.
        $user = User::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->seedSessionRow('sess-web', 'web', $user->id);
        $this->seedSessionRow('sess-admin', 'platform_admin', $admin->id);

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($user, 'web');

        $this->assertSame(1, $revoked);
        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-web')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-admin')->count());
    }

    public function test_can_exclude_the_current_session_id(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->seedSessionRow('sess-current', 'platform_admin', $admin->id);
        $this->seedSessionRow('sess-other', 'platform_admin', $admin->id);

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($admin, 'platform_admin', exceptSessionId: 'sess-current');

        $this->assertSame(1, $revoked);
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-current')->count());
        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-other')->count());
    }

    public function test_a_malformed_payload_is_treated_as_no_match_rather_than_throwing(): void
    {
        $admin = PlatformAdmin::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'sess-garbage',
            'user_id' => null,
            'payload' => 'not-valid-base64-json-at-all!!!',
            'last_activity' => now()->timestamp,
        ]);

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($admin, 'platform_admin');

        $this->assertSame(0, $revoked);
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_returns_zero_and_does_nothing_when_the_session_driver_is_not_database(): void
    {
        config(['session.driver' => 'array']);

        $admin = PlatformAdmin::factory()->create();

        $revoked = app(SessionRevocationService::class)->revokeAllSessionsFor($admin, 'platform_admin');

        $this->assertSame(0, $revoked);
    }
}
