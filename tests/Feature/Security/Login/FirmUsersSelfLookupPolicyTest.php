<?php

namespace Tests\Feature\Security\Login;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FirmUsersSelfLookupPolicyTest — internal login/panel access wiring's
 * firm_users_self_lookup RLS policy (see the 2026_08_10_900001
 * migration's own docblock for the exact bootstrap problem it solves,
 * and the real bug an earlier version of that migration introduced —
 * a single-USING-clause OR condition governed WITH CHECK too, letting
 * user-context-alone INSERT/UPDATE firm_users rows). This policy is a
 * SEPARATE, FOR SELECT-only addition: PostgreSQL never consults a FOR
 * SELECT policy for INSERT/UPDATE/DELETE, so it can only ever widen
 * what a session may READ, never what it may WRITE.
 */
class FirmUsersSelfLookupPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_context_alone_can_read_only_that_users_own_firm_users_row(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownFirmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]));
        $otherFirmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($otherUser)->create(['status' => FirmUserStatus::Active]));

        $tenantContext = new TenantContextService();

        $visibleIds = $tenantContext->withUserContext($user->id, fn () => DB::table('firm_users')->pluck('id')->all());

        $this->assertContains($ownFirmUser->id, $visibleIds);
        $this->assertNotContains($otherFirmUser->id, $visibleIds, "A user's own session context must not reveal another user's firm_users row, even in the same firm.");
    }

    public function test_user_context_alone_cannot_insert_a_firm_users_row(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $tenantContext = new TenantContextService();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $tenantContext->withUserContext($user->id, function () use ($user, $firm) {
            DB::table('firm_users')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'firm_id' => $firm->id,
                'role' => 'attorney',
                'status' => 'active',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_user_context_alone_cannot_update_a_firm_users_row(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create([
            'status' => FirmUserStatus::Active,
            'role' => 'paralegal',
        ]));

        $tenantContext = new TenantContextService();

        $affected = $tenantContext->withUserContext(
            $user->id,
            fn () => DB::table('firm_users')->where('id', $firmUser->id)->update(['role' => 'firm_owner']),
        );

        $this->assertSame(0, $affected, 'User context alone must not be able to update any firm_users row.');

        $reReadRole = $this->runWithFirmContext($firm, fn () => DB::table('firm_users')->where('id', $firmUser->id)->value('role'));
        $this->assertSame('paralegal', $reReadRole, 'The row must be genuinely unchanged, not just reported as 0 rows affected.');
    }

    public function test_user_context_alone_cannot_change_firm_id_or_user_id(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->forUser($user)->create(['status' => FirmUserStatus::Active]));

        $tenantContext = new TenantContextService();

        $affectedFirmIdChange = $tenantContext->withUserContext(
            $user->id,
            fn () => DB::table('firm_users')->where('id', $firmUser->id)->update(['firm_id' => $firmB->id]),
        );
        $affectedUserIdChange = $tenantContext->withUserContext(
            $user->id,
            fn () => DB::table('firm_users')->where('id', $firmUser->id)->update(['user_id' => $otherUser->id]),
        );

        $this->assertSame(0, $affectedFirmIdChange, 'User context alone must not be able to move a firm_users row to a different firm.');
        $this->assertSame(0, $affectedUserIdChange, 'User context alone must not be able to reassign a firm_users row to a different user.');

        $fresh = $this->runWithFirmContext($firmA, fn () => DB::table('firm_users')->where('id', $firmUser->id)->first());
        $this->assertSame($firmA->id, $fresh->firm_id);
        $this->assertSame($user->id, $fresh->user_id);
    }

    public function test_firm_context_can_still_perform_legitimate_writes(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $firmUserId = $this->runWithFirmContext($firm, function () use ($firm, $user) {
            $id = DB::table('firm_users')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'firm_id' => $firm->id,
                'role' => 'attorney',
                'status' => 'active',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('firm_users')->where('id', $id)->update(['role' => 'firm_owner']);

            return $id;
        });

        $role = $this->runWithFirmContext($firm, fn () => DB::table('firm_users')->where('id', $firmUserId)->value('role'));
        $this->assertSame('firm_owner', $role, 'Legitimate firm-context inserts and updates must remain fully functional after adding the self-lookup policy.');
    }

    public function test_migration_down_restores_the_original_firm_only_policy(): void
    {
        $migration = require base_path('database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php');

        $migration->down();

        try {
            $firm = Firm::factory()->create();
            $user = User::factory()->create();
            $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]));

            $policyExists = DB::selectOne(
                "select 1 as found from pg_policy where polrelid = 'firm_users'::regclass and polname = 'firm_users_self_lookup'"
            );
            $this->assertNull($policyExists, 'down() must drop the firm_users_self_lookup policy entirely.');

            $visibleWithUserContextOnly = (new TenantContextService())->withUserContext(
                $user->id,
                fn () => DB::table('firm_users')->where('user_id', $user->id)->count(),
            );
            $this->assertSame(0, $visibleWithUserContextOnly, 'With the self-lookup policy dropped, user context alone must no longer reveal any row — only the original firm-only policy applies.');
        } finally {
            $migration->up();
        }
    }

    public function test_user_context_clears_after_success(): void
    {
        $user = User::factory()->create();

        (new TenantContextService())->withUserContext($user->id, fn () => 'ok');

        $value = DB::selectOne("select current_setting('app.current_user_id', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_user_id must be cleared after a successful withUserContext() call.');
    }

    public function test_user_context_clears_after_exception(): void
    {
        $user = User::factory()->create();

        try {
            (new TenantContextService())->withUserContext($user->id, function () {
                throw new \RuntimeException('simulated failure');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $value = DB::selectOne("select current_setting('app.current_user_id', true) as value")->value;
        $this->assertTrue($value === null || $value === '', 'app.current_user_id must be cleared even when the callback throws.');
    }
}
