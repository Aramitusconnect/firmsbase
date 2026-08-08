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
 * ApproveInvoiceAction — calls InvoiceDraftingService::approve()
 * directly, which stamps `issued_at`. Visible only for a
 * PendingReview invoice (matches that service's own guard exactly).
 * Gated on BillingAccessPolicyService::canApproveInvoice() —
 * FirmOwner/Attorney only, deliberately narrower than
 * canDraftInvoice() (see BillingAccessPolicyService's own docblock):
 * this is the point of no return before the invoice becomes
 * collectible.
 */
class ApproveInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Approves this invoice for sending. This snapshots the issue date and is the point of no return before the invoice is considered collectible.');

        $this->visible(function (Invoice $record): bool {
            if ($record->status !== InvoiceStatus::PendingReview) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canApproveInvoice($firmUser->role);
        });

        $this->action(function (Invoice $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canApproveInvoice($firmUser->role)) {
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
                        app(InvoiceDraftingService::class)->approve($fresh);
                        Notification::make()->title('Invoice approved')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not approve invoice')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
