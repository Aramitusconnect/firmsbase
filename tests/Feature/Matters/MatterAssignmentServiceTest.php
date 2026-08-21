<?php

namespace Tests\Feature\Matters;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\MatterAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * MatterAssignmentServiceTest — Mission 5A. Proves add()/remove() and
 * the non-member rejection guard (mirrors
 * MatterCreationService::assertUserIsActiveFirmMember()'s own check —
 * this is its post-creation-management sibling).
 */
class MatterAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MatterAssignmentService;
    }

    public function test_add_creates_an_active_assignment_for_an_active_firm_member(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $assignee = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Paralegal)->create();

        $assignment = $this->service->add($matter, $assignee->user, 'paralegal', false, $actor);

        $this->assertSame($matter->id, $assignment->matter_id);
        $this->assertSame($assignee->user_id, $assignment->user_id);
        $this->assertSame('paralegal', $assignment->role);
        $this->assertFalse($assignment->is_lead);
        $this->assertNull($assignment->removed_at);
    }

    public function test_add_throws_when_user_is_not_an_active_member_of_the_matters_firm(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $strangerUser = User::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->service->add($matter, $strangerUser, 'paralegal', false, $actor);
    }

    public function test_add_throws_when_user_is_a_member_of_a_different_firm(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $otherFirmMember = FirmUser::factory()->create();

        $this->assertNotSame($matter->firm_id, $otherFirmMember->firm_id);

        $this->expectException(RuntimeException::class);

        $this->service->add($matter, $otherFirmMember->user, 'paralegal', false, $actor);
    }

    public function test_add_throws_when_user_already_has_an_active_assignment(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $assignee = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Paralegal)->create();

        $this->service->add($matter, $assignee->user, 'paralegal', false, $actor);

        $this->expectException(RuntimeException::class);

        $this->service->add($matter, $assignee->user, 'paralegal', false, $actor);
    }

    public function test_remove_sets_removed_at_rather_than_deleting_the_row(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $assignee = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Paralegal)->create();

        $assignment = $this->service->add($matter, $assignee->user, 'paralegal', false, $actor);

        $removed = $this->service->remove($matter, $assignment, $actor);

        $this->assertNotNull($removed->removed_at);
        $this->assertDatabaseHas('matter_assignments', [
            'id' => $assignment->id,
        ]);
    }

    public function test_remove_throws_when_assignment_is_already_removed(): void
    {
        $matter = Matter::factory()->create();
        $actor = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Attorney)->create();
        $assignee = FirmUser::factory()->forFirm($matter->firm)->role(FirmUserRole::Paralegal)->create();

        $assignment = $this->service->add($matter, $assignee->user, 'paralegal', false, $actor);
        $this->service->remove($matter, $assignment, $actor);

        $this->expectException(RuntimeException::class);

        $this->service->remove($matter, $assignment, $actor);
    }

    public function test_remove_throws_when_assignment_does_not_belong_to_the_given_matter(): void
    {
        $matterA = Matter::factory()->create();
        $matterB = Matter::factory()->create();
        $actorA = FirmUser::factory()->forFirm($matterA->firm)->role(FirmUserRole::Attorney)->create();
        $actorB = FirmUser::factory()->forFirm($matterB->firm)->role(FirmUserRole::Attorney)->create();
        $assigneeB = FirmUser::factory()->forFirm($matterB->firm)->role(FirmUserRole::Paralegal)->create();

        $assignmentOnB = $this->service->add($matterB, $assigneeB->user, 'paralegal', false, $actorB);

        $this->expectException(RuntimeException::class);

        $this->service->remove($matterA, $assignmentOnB, $actorA);
    }
}
