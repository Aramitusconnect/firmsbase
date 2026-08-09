<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PendingPaymentAllocationResource\Actions;

use App\Models\PendingPaymentAllocation;
use App\Services\PaymentAccessPolicyService;
use App\Services\PaymentAllocationResolutionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ResolvePaymentAllocationAction — Mixed-Invoice Revenue Allocation
 * pass, item 3/8. The ONE place an authorized Billing Staff/Firm
 * Owner/Attorney (PaymentAccessPolicyService::canResolvePaymentAllocation()
 * — same ceiling as recording a payment) resolves a
 * PendingPaymentAllocation. Wired directly to
 * PaymentAllocationResolutionService — never a bare model update.
 */
class ResolvePaymentAllocationAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolvePaymentAllocation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve Allocation');
        $this->icon(Heroicon::OutlinedScale);
        $this->color('primary');
        $this->modalHeading('Resolve Payment Allocation');
        $this->modalDescription('This invoice mixes legal-fee and reimbursable-cost lines — specify exactly how this payment splits between the two. The two amounts must sum to the pending amount.');
        $this->modalSubmitActionLabel('Resolve');
        $this->modalWidth('lg');

        $this->schema([
            TextInput::make('fee_dollars')->label('Legal Fee Amount (USD)')->numeric()->minValue(0)->prefix('$')->required(),
            TextInput::make('cost_dollars')->label('Reimbursable Cost Amount (USD)')->numeric()->minValue(0)->prefix('$')->required(),
            Textarea::make('notes')->label('Notes (optional)')->rows(2),
        ]);

        $this->visible(function (PendingPaymentAllocation $record): bool {
            if (! $record->isPending()) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && (int) $firmUser->firm_id === (int) $record->firm_id
                && app(PaymentAccessPolicyService::class)->canResolvePaymentAllocation($firmUser->role);
        });

        $this->action(function (PendingPaymentAllocation $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(PaymentAccessPolicyService::class)->canResolvePaymentAllocation($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $feeCents = (int) round(((float) $data['fee_dollars']) * 100);
            $costCents = (int) round(((float) $data['cost_dollars']) * 100);

            try {
                app(PaymentAllocationResolutionService::class)->resolve(
                    $firmUser->firm,
                    $record,
                    $firmUser,
                    $feeCents,
                    $costCents,
                    $data['notes'] ?? null,
                );
            } catch (Throwable $e) {
                Notification::make()->title('Could not resolve allocation')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Allocation resolved')->success()->send();
        });
    }
}
