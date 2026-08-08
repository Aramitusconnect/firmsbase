<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * SendInvoiceAction — calls InvoiceDraftingService::send() directly,
 * which stamps `sent_at` and records a timeline event. Visible only
 * for an Approved invoice (matches that service's own guard exactly).
 * Gated on BillingAccessPolicyService::canSendInvoice() — FirmOwner/
 * Attorney only, same narrow ceiling as Approve/Void/MarkDefaulted.
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
        $this->modalDescription('Marks this invoice as sent to the client. No real email/portal dispatch exists yet — this only records the transition.');

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
                        app(InvoiceDraftingService::class)->send($fresh);
                        Notification::make()->title('Invoice sent')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not send invoice')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
