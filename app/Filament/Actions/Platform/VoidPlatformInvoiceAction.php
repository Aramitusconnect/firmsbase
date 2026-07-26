<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlatformInvoiceStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Services\PlatformInvoiceService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * VoidPlatformInvoiceAction — PlatformInvoiceResource's View page header
 * action. Voids a Draft/Open/PastDue invoice via the actor-parameterized
 * PlatformInvoiceService::void($invoice, $actor) added in this phase's
 * backend-foundations pass (records an `invoice_voided` /
 * `platform_billing` audit event via PlatformAdminAuditEventRecorder::
 * recordPlatformEvent() when an actor is supplied).
 *
 * Same TOCTOU-safe, dual-gate shape as FinalizePlatformInvoiceAction —
 * see that class's own docblock for the full reasoning (fresh actor
 * resolution, canManagePlatformBilling() + canMutate() both checked
 * here since PlatformInvoiceService::void() carries no authorization
 * logic of its own).
 *
 * A Paid invoice is never offered Void here — voiding a paid invoice
 * would misrepresent a real (simulated) payment as never having
 * happened, with no reconciliation path back to the PlatformPayment row
 * still sitting underneath it. This mirrors the same "don't create a
 * state that contradicts an existing financial record" reasoning behind
 * markPaid() staying unexposed (see PlatformInvoiceResource's own
 * docblock) — voiding a Paid invoice is a materially different, and
 * separately-reviewable, operation (e.g. paired with a refund) that
 * this phase does not build.
 */
class VoidPlatformInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'voidPlatformInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Void');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');

        $this->schema([
            Textarea::make('reason')
                ->label('Reason (optional)')
                ->rows(2)
                ->helperText('Not persisted on the invoice itself today — for your own record while confirming this action.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Void Invoice');
        $this->modalDescription('This permanently voids the invoice. It can never be finalized, paid, or reopened afterward.');
        $this->modalSubmitActionLabel('Void');

        $this->visible(fn (PlatformInvoice $record): bool => in_array($record->status, [
            PlatformInvoiceStatus::Draft,
            PlatformInvoiceStatus::Open,
            PlatformInvoiceStatus::PastDue,
        ], true));

        $this->action(function (PlatformInvoice $record, PlatformInvoiceService $invoiceService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManagePlatformBilling($actor)->allowed) {
                Notification::make()->title('You are not authorized to manage platform billing.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $invoice = PlatformInvoice::query()->find($record->getKey());

            if ($invoice === null) {
                Notification::make()->title('That invoice could not be found.')->danger()->send();

                return;
            }

            if ($invoice->status === PlatformInvoiceStatus::Paid || $invoice->status === PlatformInvoiceStatus::Void) {
                Notification::make()
                    ->title('This invoice can no longer be voided')
                    ->body("Its status is already {$invoice->status->value}.")
                    ->warning()
                    ->send();

                return;
            }

            $voided = $invoiceService->void($invoice, $actor);

            Notification::make()
                ->title('Invoice voided')
                ->body("Status: {$voided->status->value}.")
                ->success()
                ->send();
        });
    }
}
