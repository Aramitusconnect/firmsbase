<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * VoidInvoiceAction — calls InvoiceDraftingService::void() directly.
 * Visible for any status except Void/Paid/Refunded (matches that
 * service's own guard exactly — see its docblock). Gated on
 * BillingAccessPolicyService::canVoidInvoice() — FirmOwner/Attorney
 * only, same narrow ceiling as Approve/Send/MarkDefaulted, given the
 * financial-liability weight of voiding a document that may already
 * have been sent to a client.
 */
class VoidInvoiceAction extends Action
{
    private const BLOCKED_STATUSES = [
        InvoiceStatus::Void,
        InvoiceStatus::Paid,
        InvoiceStatus::Refunded,
    ];

    public static function getDefaultName(): ?string
    {
        return 'voidInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Void');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Voids this invoice. This cannot be undone through this UI.');
        $this->modalSubmitActionLabel('Void Invoice');

        $this->schema([
            Textarea::make('reason')->label('Reason (optional)')->rows(2),
        ]);

        $this->visible(function (Invoice $record): bool {
            if (in_array($record->status, self::BLOCKED_STATUSES, true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canVoidInvoice($firmUser->role);
        });

        $this->action(function (array $data, Invoice $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canVoidInvoice($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser, $data): void {
                    $fresh = Invoice::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this invoice.')->danger()->send();

                        return;
                    }

                    try {
                        app(InvoiceDraftingService::class)->void($fresh, filled($data['reason'] ?? null) ? (string) $data['reason'] : null);
                        Notification::make()->title('Invoice voided')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not void invoice')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
