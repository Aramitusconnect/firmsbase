<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlatformInvoiceStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Services\PlatformInvoiceService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * FinalizePlatformInvoiceAction — PlatformInvoiceResource's View page
 * header action. Transitions a Draft invoice to Open via the actor-
 * parameterized PlatformInvoiceService::finalize($invoice, $actor) added
 * in this phase's backend-foundations pass (records an
 * `invoice_finalized` / `platform_billing` audit event via
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() when an actor
 * is supplied).
 *
 * TOCTOU-safe, mirroring RetrySyncFailureAction/
 * RequeueDeadLetterQueueEventAction's exact dual-gate shape: the acting
 * admin is re-resolved fresh from the guard inside the closure (never
 * trusted from page-load time), canManagePlatformBilling() is checked
 * (the narrow "manage" gate this phase added specifically for these two
 * Actions), and the blanket canMutate() rule is checked explicitly on
 * top of it — this Resource's underlying service method carries no
 * authorization logic of its own, so both checks belong here, at the UI
 * layer, exactly like every other "*AsSupportAction"/"Retry...Action"/
 * "Requeue...Action" class in this codebase.
 *
 * The target invoice is re-fetched fresh by primary key immediately
 * before the service call (never trusting the record Filament bound at
 * render time) and the transition is only offered/attempted while the
 * invoice is still Draft — Finalize on an already-Open/Paid/Void/
 * PastDue invoice is a no-op state PlatformInvoiceService::finalize()
 * itself does not guard against (it is a bare `update()`), so the
 * re-check here is what prevents a stale "Finalize" click from
 * silently re-writing an invoice that has already moved on.
 */
class FinalizePlatformInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'finalizePlatformInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Finalize');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Finalize Invoice');
        $this->modalDescription('This transitions the invoice from Draft to Open, making it payable. This cannot be undone from this action (void it instead if it was finalized in error).');
        $this->modalSubmitActionLabel('Finalize');

        $this->visible(fn (PlatformInvoice $record): bool => $record->status === PlatformInvoiceStatus::Draft);

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

            if ($invoice->status !== PlatformInvoiceStatus::Draft) {
                Notification::make()
                    ->title('This invoice can no longer be finalized')
                    ->body("Its status is already {$invoice->status->value}.")
                    ->warning()
                    ->send();

                return;
            }

            $finalized = $invoiceService->finalize($invoice, $actor);

            Notification::make()
                ->title('Invoice finalized')
                ->body("Status: {$finalized->status->value}.")
                ->success()
                ->send();
        });
    }
}
