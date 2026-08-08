<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * AddManualChargeAction — "Add Manual Charge" row action, wired
 * directly to InvoiceDraftingService::addManualCharge() — never a bare
 * `InvoiceLine::create()`. That service method itself guards "Draft
 * only" (throws otherwise); this Action's own `visible()` mirrors that
 * guard so the row action never even appears on a non-Draft invoice.
 * `amount_cents`/`total_cents` on the parent Invoice are never touched
 * by this form directly — addManualCharge() recomputes totals from
 * invoice_lines internally.
 */
class AddManualChargeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addManualCharge';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add Manual Charge');
        $this->modalHeading('Add a manual charge to this invoice');
        $this->modalSubmitActionLabel('Add Charge');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('gray');

        $this->schema([
            TextInput::make('description')->label('Description')->required()->maxLength(255),
            TextInput::make('amount')->label('Amount')->numeric()->minValue(0.01)->prefix('$')->required(),
        ]);

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

        $this->action(function (array $data, Invoice $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role)) {
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
                        app(InvoiceDraftingService::class)->addManualCharge(
                            $fresh,
                            (string) $data['description'],
                            (int) round(((float) $data['amount']) * 100),
                        );
                        Notification::make()->title('Charge added')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not add charge')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
