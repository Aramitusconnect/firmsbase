<?php

namespace Tests\Feature\Identity;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These tests exercise the additive patch to app/Models/User.php
 * described in PATCH-INSTRUCTIONS/User.php.PATCH.md. If this suite
 * fails with "Call to undefined method App\Models\User::firmUsers()"
 * or "::firms()", the patch has not yet been applied to the real file.
 */
class UserFirmRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();

        $this->assertNotEmpty($user->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $user->uuid
        );
    }

    public function test_user_uuid_is_unique_across_multiple_creates(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->assertNotSame($a->uuid, $b->uuid);
    }

    public function test_no_firm_id_column_exists_on_users(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('firm_id', $user->getAttributes());
    }

    public function test_user_has_many_firm_users(): void
    {
        $user = User::factory()->create();
        FirmUser::factory()->forUser($user)->count(2)->create();

        $this->assertCount(2, $user->firmUsers);
    }

    public function test_user_firms_belongs_to_many_through_firm_users(): void
    {
        $user = User::factory()->create();
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        FirmUser::factory()->forUser($user)->forFirm($firmA)->role(FirmUserRole::Attorney)->create();
        FirmUser::factory()->forUser($user)->forFirm($firmB)->role(FirmUserRole::Paralegal)->create();

        $this->assertCount(2, $user->firms);
        $this->assertTrue($user->firms->contains('id', $firmA->id));
        $this->assertTrue($user->firms->contains('id', $firmB->id));
    }

    public function test_two_factor_fields_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'SUPERSECRET',
            'two_factor_recovery_codes' => ['code-1', 'code-2'],
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->first();

        $this->assertStringNotContainsString('SUPERSECRET', $raw->two_factor_secret);
        $this->assertSame('SUPERSECRET', $user->fresh()->two_factor_secret);
        $this->assertSame(['code-1', 'code-2'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertIsBool($user->fresh()->is_active);
    }

    public function test_datetime_fields_cast_correctly(): void
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
            'invitation_accepted_at' => now(),
            'invitation_expires_at' => now()->addDays(7),
            'last_login_at' => now(),
        ]);

        $fresh = $user->fresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->two_factor_confirmed_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->invitation_accepted_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->invitation_expires_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->last_login_at);
    }

    public function test_last_login_ip_column_accepts_full_length_ipv6(): void
    {
        $user = User::factory()->create([
            'last_login_ip' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
        ]);

        $this->assertSame('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $user->fresh()->last_login_ip);
    }
}
