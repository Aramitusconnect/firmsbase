<?php

namespace Tests\Feature\Identity;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $firmUser = FirmUser::factory()->create();

        $this->assertDatabaseHas('firm_users', ['id' => $firmUser->id]);
    }

    public function test_role_has_exactly_six_values_and_no_client(): void
    {
        $values = array_map(fn ($case) => $case->value, FirmUserRole::cases());

        $this->assertCount(6, FirmUserRole::cases());
        $this->assertNotContains('client', $values);
        $this->assertEqualsCanonicalizing(
            ['firm_owner', 'attorney', 'paralegal', 'legal_assistant', 'receptionist', 'billing_staff'],
            $values
        );
    }

    public function test_one_user_cannot_be_added_twice_to_the_same_firm(): void
    {
        $user = User::factory()->create();
        $firm = Firm::factory()->create();

        FirmUser::factory()->forUser($user)->forFirm($firm)->create();

        $this->expectException(QueryException::class);

        FirmUser::factory()->forUser($user)->forFirm($firm)->create();
    }

    public function test_same_user_can_belong_to_multiple_firms(): void
    {
        $user = User::factory()->create();
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        FirmUser::factory()->forUser($user)->forFirm($firmA)->create();
        FirmUser::factory()->forUser($user)->forFirm($firmB)->create();

        $this->assertSame(2, FirmUser::where('user_id', $user->id)->count());
    }
}
