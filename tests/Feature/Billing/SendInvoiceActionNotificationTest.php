<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\NotificationTemplateStatus;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SendInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Pages\ListInvoices;
use App\Jobs\DispatchNotificationJob;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SendInvoiceActionNotificationTest — Mission 6 (Real Communications &
 * Notification Delivery), item 6.3. Proves SendInvoiceAction now
 * triggers a NotificationDispatchService::dispatch() call (template key
 * 'invoice_sent') after InvoiceDraftingService::send() succeeds — the
 * gap this mission closes: before this change, SendInvoiceAction only
 * ever recorded the status transition, with no dispatch call of any
 * kind.
 */
final class SendInvoiceActionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    public function test_sending_an_invoice_queues_an_invoice_sent_notification_dispatch(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Approved)->create());
        $client = $this->runWithFirmContext($firm, fn () => $invoice->client()->first());

        $this->runWithFirmContext($firm, function () use ($firm, $client): void {
            NotificationTemplate::factory()->domainVerified()->create([
                'firm_id' => null,
                'key' => 'invoice_sent',
                'channel' => ConsentChannel::Email,
                'status' => NotificationTemplateStatus::Active,
                'subject' => 'A new invoice is available',
                'body' => 'A new invoice has been issued for your matter.',
            ]);

            CommunicationConsent::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'channel' => ConsentChannel::Email,
                'status' => ConsentStatus::Granted,
                'granted_at' => now(),
            ]);
        });

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->callTableAction(SendInvoiceAction::getDefaultName(), $invoice);
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(InvoiceStatus::Sent, $fresh->status);

        Queue::assertPushed(DispatchNotificationJob::class, fn (DispatchNotificationJob $job) => $job->firmId === $firm->id
            && $job->clientId === $client->id
            && $job->recipient === $client->email
        );
    }

    /**
     * A notification-dispatch failure (here: simply no eligible
     * template/consent exists) must never roll back or block the
     * already-committed invoice status transition — this is the
     * explicit "log it, don't throw" contract from the mission spec.
     */
    public function test_sending_an_invoice_still_succeeds_when_no_notification_template_is_configured(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forFirm($firm)->status(InvoiceStatus::Approved)->create());

        $this->runWithFirmContext($firm, function () use ($invoice): void {
            $test = Livewire::test(ListInvoices::class);
            $test->callTableAction(SendInvoiceAction::getDefaultName(), $invoice);
            $test->assertNotified('Invoice sent');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Invoice::query()->find($invoice->id));
        $this->assertSame(InvoiceStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->sent_at);

        Queue::assertNothingPushed();
    }
}
