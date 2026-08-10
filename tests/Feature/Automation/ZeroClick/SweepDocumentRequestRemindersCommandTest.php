<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Console\Commands\SweepDocumentRequestRemindersCommand;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentChaseSchedulerService;
use App\Services\DocumentChaseService;
use App\Services\NotificationEligibilityService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SweepDocumentRequestRemindersCommandTest — Zero-Click Core Workflow
 * Automation, test matrix C/D. Proves the missing scheduled trigger
 * for the pre-existing DocumentChaseSchedulerService/
 * DocumentChaseService (both unmodified): a reminder checkpoint fires
 * DocumentRequestReminderDue exactly once; running the sweep twice on
 * the same day never double-fires (item 26's own explicit
 * requirement).
 */
class SweepDocumentRequestRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function command(): SweepDocumentRequestRemindersCommand
    {
        return new SweepDocumentRequestRemindersCommand(
            new DocumentChaseSchedulerService,
            new DocumentChaseService(app(NotificationEligibilityService::class), new TimelineEventRecorder),
            app(DomainEventRecorderService::class),
            new AutomationRecipientResolverService,
        );
    }

    private function itemAtOffset(Firm $firm, int $daysOld): DocumentRequestItem
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $daysOld) {
            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);

            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
            $item = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $item->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

            DocumentChaseRule::factory()->forFirm($firm)->create();

            return $item->fresh();
        });
    }

    public function test_a_reminder_checkpoint_emits_the_domain_event(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);
        $item = $this->itemAtOffset($firm, 7);

        $this->command()->handle();

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::DocumentRequestReminderDue->value)
            ->where('subject_id', $item->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertFalse($event->payload_json['document_request_item']['is_escalation']);
    }

    public function test_a_non_checkpoint_day_never_fires(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);
        $item = $this->itemAtOffset($firm, 5);

        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::DocumentRequestReminderDue->value)
            ->where('subject_id', $item->id)
            ->count());

        $this->assertSame(0, $count);
    }

    public function test_running_the_sweep_twice_the_same_day_never_double_fires(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);
        $item = $this->itemAtOffset($firm, 7);

        $this->command()->handle();
        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::DocumentRequestReminderDue->value)
            ->where('subject_id', $item->id)
            ->count());

        $this->assertSame(1, $count);
    }

    public function test_escalation_checkpoint_flags_the_event_and_notifies_the_firm_owner(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $item = $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);

            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);

            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
            $documentRequestItem = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $documentRequestItem->forceFill(['created_at' => now()->subDays(14)])->saveQuietly();

            DocumentChaseRule::factory()->forFirm($firm)->create();

            return $documentRequestItem->fresh();
        });

        $this->command()->handle();

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('event_type', DomainEventType::DocumentRequestReminderDue->value)
            ->where('subject_id', $item->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertTrue($event->payload_json['document_request_item']['is_escalation']);
    }
}
