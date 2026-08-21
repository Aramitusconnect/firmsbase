<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceWriteOffService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * WriteOffInvoiceAction — calls InvoiceWriteOffService::writeOff()
 * directly, the sole writer of invoice_write_offs/
 * InvoiceStatus::WrittenOff (see that service's own docblock). Visible
 * only when the invoice's current status is one the service actually
 * allows (i.e. NOT Draft/Void/Paid/Refunded/already-WrittenOff — see
 * writeOff()'s own guard) AND it still carries a remaining unpaid
 * balance (total_cents - amount_paid_cents > 0) — the exact same
 * `$remainingCents <= 0` guard the service itself throws on, checked
 * here first purely so the action never surfaces (and never has to
 * round-trip through the service) for an invoice that has nothing left
 * to write off.
 *
 * Gated on BillingAccessPolicyService::canVoidInvoice() — no dedicated
 * canWriteOffInvoice() ceiling exists (and none is added here per this
 * batch's ownership boundary), and a write-off carries the exact same
 * "point of no return on financial liability" weight the service's own
 * docblock describes ("we will never collect the rest") as Void does,
 * so it is held to the identical FirmOwner/Attorney-only ceiling as
 * VoidInvoiceAction rather than the wider DRAFT tier.
 */
class WriteOffInvoiceAction extends Action
{
    private const BLOCKED_STATUSES = [
        InvoiceStatus::Draft,
        InvoiceStatus::Void,
        InvoiceStatus::Paid,
        InvoiceStatus::Refunded,
        InvoiceStatus::WrittenOff,
    ];

    public static function getDefaultName(): ?string
    {
        return 'writeOffInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Write Off');
        $this->icon(Heroicon::OutlinedArchiveBoxXMark);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Writes off this invoice\'s remaining unpaid balance. The already-paid portion is untouched. This cannot be undone through this UI.');
        $this->modalSubmitActionLabel('Write Off Invoice');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

        $this->visible(function (Invoice $record): bool {
            if (in_array($record->status, self::BLOCKED_STATUSES, true)) {
                return false;
            }

            if (($record->total_cents - $record->amount_paid_cents) <= 0) {
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
                        app(InvoiceWriteOffService::class)->writeOff($fresh->firm, $fresh, (string) $data['reason'], $firmUser);
                        Notification::make()->title('Invoice written off')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not write off invoice')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
