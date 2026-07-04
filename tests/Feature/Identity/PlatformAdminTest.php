<?php

namespace Tests\Feature\Identity;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertDatabaseHas('platform_admins', ['id' => $admin->id]);
    }

    public function test_password_is_hidden(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertArrayNotHasKey('password', $admin->toArray());
    }

    public function test_two_factor_fields_are_encrypted_at_rest(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'SUPERSECRET',
            'two_factor_recovery_codes' => ['code-1', 'code-2'],
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('platform_admins')->where('id', $admin->id)->first();

        $this->assertStringNotContainsString('SUPERSECRET', $raw->two_factor_secret);
        $this->assertStringNotContainsString('code-1', $raw->two_factor_recovery_codes);

        $this->assertSame('SUPERSECRET', $admin->fresh()->two_factor_secret);
        $this->assertSame(['code-1', 'code-2'], $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->assertIsBool($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }
}
