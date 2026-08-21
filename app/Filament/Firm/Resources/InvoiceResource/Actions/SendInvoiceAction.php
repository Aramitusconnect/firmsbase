<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\ConsentChannel;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\NotificationDispatchService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendInvoiceAction — calls InvoiceDraftingService::send() directly,
 * which stamps `sent_at` and records a timeline event. Visible only
 * for an Approved invoice (matches that service's own guard exactly).
 * Gated on BillingAccessPolicyService::canSendInvoice() — FirmOwner/
 * Attorney only, same narrow ceiling as Approve/Void/MarkDefaulted.
 *
 * Mission 6 (Real Communications & Notification Delivery): once the
 * status transition itself has succeeded, this also triggers a real
 * template-driven notification via NotificationDispatchService::
 * dispatch() (template key 'invoice_sent' — see
 * database/seeders/NotificationTemplateSeeder.php for the seeded
 * default). A notification-dispatch failure is caught and logged, and
 * deliberately never rolls back or re-reports as an error the invoice
 * status transition that already committed — the two are independent
 * concerns, and the send action's own success/failure notice reflects
 * only the status transition.
 */
class SendInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send');
        $this->icon(Heroicon::OutlinedPaperAirplane);
        $this->color('primary');
        $this->requiresConfirmation();
        $this->modalDescription('Marks this invoice as sent and emails the client a notification, if eligible.');

        $this->visible(function (Invoice $record): bool {
            if ($record->status !== InvoiceStatus::Approved) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canSendInvoice($firmUser->role);
        });

        $this->action(function (Invoice $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canSendInvoice($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = Invoice::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this invoice.')->danger()->send();

                        return;
                    }

                    try {
                        $sent = app(InvoiceDraftingService::class)->send($fresh);
                        Notification::make()->title('Invoice sent')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not send invoice')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    self::dispatchSentNotification($sent);
                },
            );
        });
    }

    /**
     * Best-effort only — see class docblock. Runs after the invoice's
     * own status transition has already committed, so any failure here
     * must never surface as if the send itself failed.
     */
    private static function dispatchSentNotification(Invoice $invoice): void
    {
        $client = $invoice->client;

        if ($client === null || ! is_string($client->email) || trim($client->email) === '') {
            return;
        }

        try {
            app(NotificationDispatchService::class)->dispatch(
                firm: $invoice->firm,
                client: $client,
                channel: ConsentChannel::Email,
                recipient: $client->email,
                templateKey: 'invoice_sent',
                language: $client->preferred_language ?? 'en',
                subject: $invoice,
                matter: $invoice->matter,
            );
        } catch (Throwable $e) {
            report($e);

            Log::warning('send_invoice_action_notification_dispatch_failed', [
                'invoice_id' => $invoice->id,
                'firm_id' => $invoice->firm_id,
                'exception' => $e::class,
            ]);
        }
    }
}
