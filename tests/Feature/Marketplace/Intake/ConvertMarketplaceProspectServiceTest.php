<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Enums\MatterStatus;
use App\Enums\WebhookEventType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\ConvertMarketplaceProspectService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Client;
use App\Models\Document;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 11 —
 * ConvertMarketplaceProspectService: the bridge from an Accepted
 * MarketplaceIntake to a real Client + Matter, exclusively through the
 * existing canonical LeadConversionService/MatterCreationService — this
 * service itself never writes a Client or Matter row directly.
 */
class ConvertMarketplaceProspectServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConvertMarketplaceProspectService $service;

    private MarketplaceIntakeService $intakeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConvertMarketplaceProspectService::class);
        $this->intakeService = new MarketplaceIntakeService;
    }

    private function acceptedIntake(Firm $firm, ?PracticeArea $practiceArea = null): MarketplaceIntake
    {
        $practiceArea ??= PracticeArea::factory()->create();

        $intake = $this->intakeService->start($firm);
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'practice_area_id' => $practiceArea->id,
            'prospect_name' => 'Jordan Prospect',
            'prospect_email' => 'jordan@example.com',
            'prospect_phone' => '555-0100',
        ]));

        $submitted = $this->intakeService->markSubmitted($firm, $intake);

        return $this->intakeService->markAccepted($firm, $submitted);
    }

    public function test_convert_creates_a_firm_lead_client_and_matter(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $matter = $this->service->convert($firm, $intake, $matterType->id);

        $this->assertInstanceOf(Matter::class, $matter);
        $this->assertSame($firm->id, $matter->firm_id);
        $this->assertSame($practiceArea->id, $matter->primary_practice_area_id);
        $this->assertSame($matterType->id, $matter->matter_type_id);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $client = Client::query()->where('id', $matter->client_id)->firstOrFail();
            $this->assertSame($firm->id, $client->firm_id);
            $this->assertSame('Jordan Prospect', $client->display_name);

            $lead = FirmLead::query()->where('firm_id', $firm->id)->sole();
            $this->assertSame($client->id, $lead->converted_client_id);
        });
    }

    public function test_convert_transitions_the_intake_to_converted_and_records_ids(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $matter = $this->service->convert($firm, $intake, $matterType->id);

        $fresh = $this->runWithFirmContext($firm, fn () => $intake->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Converted, $fresh->status);
        $this->assertSame($matter->client_id, $fresh->converted_client_id);
        $this->assertNotNull($fresh->converted_firm_lead_id);
        $this->assertNotNull($fresh->converted_at);

        $events = $this->runWithFirmContext($firm, fn () => $intake->events()->pluck('event_type')->all());
        $this->assertContains(MarketplaceIntakeEventType::Converted, $events);
    }

    public function test_convert_rejects_an_intake_that_is_not_accepted(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->intakeService->start($firm);
        $this->runWithFirmContext($firm, fn () => $intake->update(['practice_area_id' => $practiceArea->id]));

        $this->expectException(\RuntimeException::class);

        $this->service->convert($firm, $intake, $matterType->id);
    }

    public function test_convert_rejects_an_already_converted_intake(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $this->service->convert($firm, $intake, $matterType->id);
        $reconverted = $this->runWithFirmContext($firm, fn () => $intake->fresh());

        $this->expectException(\RuntimeException::class);

        $this->service->convert($firm, $reconverted, $matterType->id);
    }

    public function test_convert_rejects_an_intake_with_no_practice_area(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->intakeService->start($firm);
        $submitted = $this->intakeService->markSubmitted($firm, $intake);
        $accepted = $this->intakeService->markAccepted($firm, $submitted);
        $matterType = MatterType::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->convert($firm, $accepted, $matterType->id);
    }

    public function test_convert_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $this->expectException(\RuntimeException::class);

        $this->service->convert($otherFirm, $intake, $matterType->id);
    }

    public function test_convert_leaves_the_matter_in_draft_status(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $matter = $this->service->convert($firm, $intake, $matterType->id);

        $this->assertSame(MatterStatus::Draft, $matter->status);
    }

    public function test_convert_relinks_only_scan_clean_documents(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        [$clean, $infected, $pending] = $this->runWithFirmContext($firm, function () use ($firm, $intake) {
            $clean = Document::factory()->create([
                'firm_id' => $firm->id,
                'marketplace_intake_id' => $intake->id,
                'matter_id' => null,
                'client_id' => null,
                'status' => DocumentStatus::Uploaded,
                'scan_status' => DocumentScanStatus::Clean,
            ]);
            $infected = Document::factory()->create([
                'firm_id' => $firm->id,
                'marketplace_intake_id' => $intake->id,
                'matter_id' => null,
                'client_id' => null,
                'status' => DocumentStatus::Rejected,
                'scan_status' => DocumentScanStatus::Infected,
            ]);
            $pending = Document::factory()->create([
                'firm_id' => $firm->id,
                'marketplace_intake_id' => $intake->id,
                'matter_id' => null,
                'client_id' => null,
                'status' => DocumentStatus::Uploaded,
                'scan_status' => DocumentScanStatus::Pending,
            ]);

            return [$clean, $infected, $pending];
        });

        $matter = $this->service->convert($firm, $intake, $matterType->id);

        $this->runWithFirmContext($firm, function () use ($matter, $clean, $infected, $pending) {
            $this->assertSame($matter->id, $clean->fresh()->matter_id);
            $this->assertSame($matter->client_id, $clean->fresh()->client_id);
            $this->assertNull($infected->fresh()->matter_id);
            $this->assertNull($pending->fresh()->matter_id);
        });
    }

    public function test_convert_fires_matter_created_webhook_event(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $recorder = Mockery::mock(WebhookEventRecorderService::class);
        $recorder->shouldReceive('record')
            ->once()
            ->with(Mockery::on(fn (Firm $f) => $f->id === $firm->id), WebhookEventType::MatterCreated, Mockery::type(Matter::class));
        $this->app->instance(WebhookEventRecorderService::class, $recorder);

        $this->service->convert($firm, $intake, $matterType->id);
    }

    public function test_convert_emits_client_created_and_matter_created_domain_events(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $matter = $this->service->convert($firm, $intake, $matterType->id);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $clientCreated = DomainEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', DomainEventType::ClientCreated)
                ->sole();
            $this->assertSame($matter->client_id, $clientCreated->payload_json['client']['id']);

            $matterCreated = DomainEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', DomainEventType::MatterCreated)
                ->sole();
            $this->assertSame($matter->id, $matterCreated->payload_json['matter']['id']);
            $this->assertSame($matter->client_id, $matterCreated->payload_json['matter']['client_id']);
        });
    }

    public function test_convert_assigns_the_given_attorney(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $intake = $this->acceptedIntake($firm, $practiceArea);

        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create([
            'firm_id' => $firm->id,
            'role' => FirmUserRole::Attorney,
            'status' => FirmUserStatus::Active,
        ]));

        $matter = $this->service->convert($firm, $intake, $matterType->id, assignedAttorneyId: $attorney->user_id);

        $this->assertSame($attorney->user_id, $matter->assigned_attorney_id);
    }
}
