<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Enums\NotificationEventStatus;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\NotificationEvent;
use App\Models\NotificationTemplate;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\AutomationTemplateInstallService;
use App\Services\Automation\DomainEventClaimService;
use App\Services\Automation\DomainEventRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InvoiceOverdueClientReminderWorkflowTest — Zero-Click Core Workflow
 * Automation, test matrix E/G. End-to-end proof: the
 * invoice_overdue_client_reminder starter template installs as a
 * normal Firm-owned rule and, once InvoiceOverdue fires, flows through
 * the REAL Automation Engine to a real, consent-gated NotificationEvent
 * — never a bespoke reminder path.
 */
class InvoiceOverdueClientReminderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_starter_template_delivers_a_real_client_reminder_through_the_automation_engine(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $client = $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);

            app(AutomationTemplateInstallService::class)->install($firm, $owner, 'invoice_overdue_client_reminder');

            // NotificationTemplateFactory's own create() clears the
            // ambient tenant context for this global (firm_id=null)
            // template — created FIRST, before any firm-scoped factory
            // re-establishes context, so the later
            // DomainEventRecorderService::record() call (which opens
            // no context of its own — see its own docblock) still runs
            // under an active app.current_firm_id.
            NotificationTemplate::factory()->domainVerified()->create(['key' => 'invoice_overdue_reminder', 'channel' => ConsentChannel::Email]);

            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);

            app(DomainEventRecorderService::class)->record($firm, DomainEventType::InvoiceOverdue, [
                'invoice' => ['id' => 1, 'status' => 'sent', 'balance_due_cents' => 10000, 'total_cents' => 10000, 'days_overdue' => 20, 'bucket' => '1_30'],
                'client' => ['id' => $client->id],
                'matter' => ['id' => null],
            ]);

            return $client;
        });

        (new AutomationEventDispatchJob($firm->id))->handle(app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class));
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $notification = $this->runWithFirmContext($firm, fn () => NotificationEvent::query()
            ->where('client_id', $client->id)
            ->where('status', NotificationEventStatus::Queued)
            ->first());

        $this->assertNotNull($notification);
    }

    public function test_the_starter_template_never_reacts_below_the_fourteen_day_threshold(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $client = $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);

            app(AutomationTemplateInstallService::class)->install($firm, $owner, 'invoice_overdue_client_reminder');

            // NotificationTemplateFactory's own create() clears the
            // ambient tenant context for this global (firm_id=null)
            // template — created FIRST, before any firm-scoped factory
            // re-establishes context, so the later
            // DomainEventRecorderService::record() call (which opens
            // no context of its own — see its own docblock) still runs
            // under an active app.current_firm_id.
            NotificationTemplate::factory()->domainVerified()->create(['key' => 'invoice_overdue_reminder', 'channel' => ConsentChannel::Email]);

            $client = Client::factory()->forFirm($firm)->create(['email' => 'client@example.test']);
            CommunicationConsent::factory()->forClient($client)->channel(ConsentChannel::Email)->create(['status' => ConsentStatus::Granted]);

            app(DomainEventRecorderService::class)->record($firm, DomainEventType::InvoiceOverdue, [
                'invoice' => ['id' => 2, 'status' => 'sent', 'balance_due_cents' => 10000, 'total_cents' => 10000, 'days_overdue' => 7, 'bucket' => '1_30'],
                'client' => ['id' => $client->id],
                'matter' => ['id' => null],
            ]);

            return $client;
        });

        (new AutomationEventDispatchJob($firm->id))->handle(app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class));
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $count = $this->runWithFirmContext($firm, fn () => NotificationEvent::query()->where('client_id', $client->id)->count());

        $this->assertSame(0, $count);
    }
}
