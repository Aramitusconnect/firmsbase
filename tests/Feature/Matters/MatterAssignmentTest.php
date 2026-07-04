<?php

namespace Tests\Feature\Matters;

use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $assignment = MatterAssignment::factory()->create();

        $this->assertDatabaseHas('matter_assignments', ['id' => $assignment->id]);
    }

    public function test_is_active_true_when_not_removed(): void
    {
        $assignment = MatterAssignment::factory()->create();

        $this->assertTrue($assignment->isActive());
    }

    public function test_is_active_false_after_removal(): void
    {
        $assignment = MatterAssignment::factory()->create(['removed_at' => now()]);

        $this->assertFalse($assignment->fresh()->isActive());
    }

    public function test_unique_matter_user_pair(): void
    {
        $matter = Matter::factory()->create();
        $user = User::factory()->create();

        MatterAssignment::factory()->forMatter($matter)->forUser($user)->create();

        $this->expectException(QueryException::class);

        MatterAssignment::factory()->forMatter($matter)->forUser($user)->create();
    }
}
