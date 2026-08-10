<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\NotificationTemplate;
use App\Models\Task;
use App\Services\Automation\Actions\NotifyClientActionHandler;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\NotificationDispatchService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotifyClientActionHandlerTest — Zero-Click Core Workflow Automation,
 * test matrix L/M. Proves the "if real delivery transport is
 * unavailable, create a staff task — never fake a successful send"
 * rule (item 11), reusing the EXISTING, unmodified consent/eligibility/
 * dispatch pipeline for the decision.
 */
class NotifyClientActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): NotifyClientActionHandler
    {
        return new NotifyClientActionHandler(
            app(NotificationDispatchService::class),
            app(TaskService::class),
            new AutomationRecipientResolverService,
        );
    }

    private function domainEventFor(Firm $firm, Client $client, ?Matter $matter = null): DomainEvent
    {
        return $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->create([
            'firm_id' => $firm->id,
            'event_type' => DomainEventType::InvoiceOverdue,
            'payload_json' => [
                'client' => ['id' => $client->id],
                'matter' => ['id' => $matter?->id],
            ],
        ]));
    }

    public function test_consent_denied_creates_a_review_task_never_fakes_a_send(): void
    {
        $firm = Firm::factory()->create();

        [$event, $attorney] = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);
            $attorney = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]);
            $matter->update(['assigned_attorney_id' => $attorney->user_id]);

            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->revoked()->create();
            NotificationTemplate::factory()->domainVerified()->create(['key' => 'invoice_overdue_reminder', 'channel' => ConsentChannel::Email]);

            return [$this->domainEventFor($firm, $client, $matter), $attorney];
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, ['template_key' => 'invoice_overdue_reminder']));

        $this->assertFalse($outcome->skipped);
        $this->assertSame(Task::class, $outcome->resultReferenceType);
    }

    public function test_no_active_template_creates_a_review_task_never_fakes_a_send(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);
            $attorney = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]);
            $matter->update(['assigned_attorney_id' => $attorney->user_id]);

            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);

            return $this->domainEventFor($firm, $client, $matter);
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, ['template_key' => 'no_such_template']));

        $this->assertFalse($outcome->skipped);
        $this->assertSame(Task::class, $outcome->resultReferenceType);
    }

    public function test_an_unverified_sender_domain_creates_a_review_task_never_fakes_a_send(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);
            $attorney = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]);
            $matter->update(['assigned_attorney_id' => $attorney->user_id]);

            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);
            NotificationTemplate::factory()->domainUnverified()->create(['key' => 'invoice_overdue_reminder', 'channel' => ConsentChannel::Email]);

            return $this->domainEventFor($firm, $client, $matter);
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, ['template_key' => 'invoice_overdue_reminder']));

        $this->assertFalse($outcome->skipped);
        $this->assertSame(Task::class, $outcome->resultReferenceType);
    }

    public function test_eligible_client_with_a_verified_template_is_accepted_and_never_creates_a_review_task(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);

            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);
            NotificationTemplate::factory()->domainVerified()->create(['key' => 'invoice_overdue_reminder', 'channel' => ConsentChannel::Email]);

            return $this->domainEventFor($firm, $client, $matter);
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, ['template_key' => 'invoice_overdue_reminder']));

        $this->assertFalse($outcome->skipped);
        $this->assertNull($outcome->resultReferenceType);
    }

    public function test_no_resolvable_client_is_skipped(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->create([
            'firm_id' => $firm->id,
            'event_type' => DomainEventType::InvoiceOverdue,
            'payload_json' => ['client' => ['id' => null], 'matter' => ['id' => null]],
        ]));

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, ['template_key' => 'invoice_overdue_reminder']));

        $this->assertTrue($outcome->skipped);
    }
}
