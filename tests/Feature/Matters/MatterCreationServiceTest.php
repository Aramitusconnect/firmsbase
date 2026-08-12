<?php

declare(strict_types=1);

namespace Tests\Feature\Matters;

use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Models\User;
use App\Services\MatterCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * MatterCreationServiceTest — proves the new MatterCreationService
 * (Firm Feature Manifest §2's confirmed "no general create-a-matter
 * service" gap) in isolation, independent of any Filament UI:
 *
 *   1. Creation succeeds with valid inputs and always lands in Draft
 *      status (never Open, never any other status — the service
 *      accepts no $status parameter at all).
 *   2. Creation is rejected when the client does not belong to the
 *      given firm (ownership-consistency guard, mirroring
 *      CalendarEventService::assertBelongsToFirm()'s established
 *      pattern).
 *   3. Creation is rejected when the matter type does not belong to
 *      the given practice area.
 *   4. Creation is rejected when the assigned attorney / assigned
 *      staff are not active FirmUsers of the given firm (including a
 *      cross-firm user).
 *   5. Optional assigned-staff ids create real, active
 *      MatterAssignment rows.
 *   6. Required fields (client, practice area, matter type) are
 *      enforced by PHP's own type system (non-nullable constructor
 *      params) — proven via a reflection-based signature check rather
 *      than trying to call the method with missing arguments (which
 *      would be a compile-time TypeError, not a runtime path worth
 *      re-testing per argument).
 */
final class MatterCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_succeeds_with_valid_inputs_and_lands_in_draft_status(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create());
        $staff = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create());

        $matter = app(MatterCreationService::class)->create(
            $firm,
            $client,
            $practiceArea->id,
            $matterType->id,
            $attorney->user_id,
            'Initial intake',
            [$staff->user_id],
        );

        $this->assertInstanceOf(Matter::class, $matter);
        $this->assertSame(MatterStatus::Draft, $matter->status);
        $this->assertSame($firm->id, $matter->firm_id);
        $this->assertSame($client->id, $matter->client_id);
        $this->assertSame($practiceArea->id, $matter->primary_practice_area_id);
        $this->assertSame($matterType->id, $matter->matter_type_id);
        $this->assertSame($attorney->user_id, $matter->assigned_attorney_id);
        $this->assertSame('Initial intake', $matter->stage);
        $this->assertNull($matter->opened_at);
        $this->assertNull($matter->closed_at);

        $assignment = $this->runWithFirmContext(
            $firm,
            fn () => MatterAssignment::query()->where('matter_id', $matter->id)->where('user_id', $staff->user_id)->first(),
        );
        $this->assertNotNull($assignment, 'Expected an active MatterAssignment row for the given staff user_id.');
        $this->assertNull($assignment->removed_at);
    }

    public function test_create_never_produces_an_open_matter_even_implicitly(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();

        $matter = app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, $matterType->id);

        $this->assertNotSame(MatterStatus::Open, $matter->status);
        $this->assertFalse($matter->isOpenOrBeyond(), 'A freshly created matter must never be open-or-beyond.');
    }

    public function test_create_rejects_a_client_that_does_not_belong_to_the_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the given client belongs to firm');

        app(MatterCreationService::class)->create($firm, $foreignClient, $practiceArea->id, $matterType->id);
    }

    public function test_create_rejects_a_matter_type_that_does_not_belong_to_the_practice_area(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $otherPracticeArea = PracticeArea::factory()->create();
        $mismatchedMatterType = MatterType::factory()->forPracticeArea($otherPracticeArea)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to practice area');

        app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, $mismatchedMatterType->id);
    }

    public function test_create_rejects_an_assigned_attorney_from_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $foreignAttorney = $this->runWithFirmContext($otherFirm, fn () => FirmUser::factory()->forFirm($otherFirm)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an active member of firm');

        app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, $matterType->id, $foreignAttorney->user_id);
    }

    public function test_create_rejects_an_assigned_staff_member_who_is_not_an_active_firm_user(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $suspendedStaff = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Suspended]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an active member of firm');

        app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, $matterType->id, null, null, [$suspendedStaff->user_id]);
    }

    public function test_create_rejects_a_nonexistent_matter_type(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, 999999);
    }

    public function test_create_emits_a_matter_created_domain_event(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();

        $matter = app(MatterCreationService::class)->create($firm, $client, $practiceArea->id, $matterType->id);

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', DomainEventType::MatterCreated)
            ->sole());

        $this->assertSame($matter->getMorphClass(), $event->subject_type);
        $this->assertSame($matter->id, $event->subject_id);
        $this->assertSame($matter->id, $event->payload_json['matter']['id']);
        $this->assertSame($client->id, $event->payload_json['matter']['client_id']);
        $this->assertSame(MatterStatus::Draft->value, $event->payload_json['matter']['status']);
    }

    public function test_create_method_signature_requires_client_practice_area_and_matter_type(): void
    {
        $reflection = new \ReflectionMethod(MatterCreationService::class, 'create');
        $required = array_filter($reflection->getParameters(), fn (\ReflectionParameter $p) => ! $p->isOptional());
        $requiredNames = array_map(fn (\ReflectionParameter $p) => $p->getName(), $required);

        $this->assertSame(['firm', 'client', 'primaryPracticeAreaId', 'matterTypeId'], $requiredNames);
    }
}
