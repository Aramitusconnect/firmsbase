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
 * SubmitInvoiceForReviewAction — calls
 * InvoiceDraftingService::submitForReview() directly. Visible only for
 * a Draft invoice (matches that service's own guard exactly). Gated on
 * BillingAccessPolicyService::canDraftInvoice() — the same ceiling as
 * drafting/adding a manual charge, since submitting for review is
 * still ordinary billing-office paperwork, not yet the point of real
 * financial liability (that's Approve, held to the narrower ceiling).
 */
class SubmitInvoiceForReviewAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'submitInvoiceForReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Submit for Review');
        $this->icon(Heroicon::OutlinedPaperAirplane);
        $this->color('info');
        $this->requiresConfirmation();

        $this->visible(function (Invoice $record): bool {
            if ($record->status !== InvoiceStatus::Draft) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role);
        });

        $this->action(function (Invoice $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role)) {
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
                        app(InvoiceDraftingService::class)->submitForReview($fresh);
                        Notification::make()->title('Invoice submitted for review')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not submit invoice')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
