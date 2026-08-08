<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustApprovalEventType;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustDepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * DenyDepositAction — "Deny Pending Deposit" header action on
 * ViewTrustLedger, wired directly to TrustDepositService::denyDeposit().
 * Not one of the three Deposit Actions explicitly named in the Trust
 * Feature Manifest ("request -> approve -> post"), added here as a
 * fourth because TrustDepositService::denyDeposit() is a real,
 * approver-gated method that exists specifically so a bad/stale deposit
 * request can be explicitly rejected rather than left permanently
 * pending — mirrors DenyTransferAction/DenyRefundAction/
 * DenyAdjustmentAction, all of which the manifest DOES name explicitly
 * for their own workflows. See ApproveDepositAction's docblock for why
 * pending requests are surfaced via a scoped Select rather than a
 * relation-backed table.
 */
class DenyDepositAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'denyDeposit';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deny Deposit');
        $this->modalHeading('Deny a Pending Deposit Request');
        $this->modalSubmitActionLabel('Deny');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->schema([
            Select::make('approval_event_id')
                ->label('Pending Deposit Request')
                ->options(fn (TrustLedger $record): array => self::pendingRequestOptions($record))
                ->searchable()
                ->required(),
        ]);

        $this->visible(function (TrustLedger $record): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if (! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                return false;
            }

            return filled(self::pendingRequestOptions($record));
        });

        $this->action(function (array $data, TrustLedger $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this ledger.')->danger()->send();

                    return;
                }

                $event = TrustApprovalEvent::query()
                    ->where('id', $data['approval_event_id'])
                    ->where('firm_id', $firmUser->firm_id)
                    ->where('trust_ledger_id', $record->id)
                    ->first();

                if ($event === null) {
                    Notification::make()->title('Could not deny deposit')->body('The selected request could not be found.')->danger()->send();

                    return;
                }

                try {
                    app(TrustDepositService::class)->denyDeposit($firmUser->firm, $event, $firmUser);
                    Notification::make()->title('Deposit denied')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not deny deposit')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    /**
     * @return array<int, string>
     */
    private static function pendingRequestOptions(TrustLedger $record): array
    {
        return self::firmScoped(function () use ($record): array {
            $firmUser = self::activeFirmUser();

            return TrustApprovalEvent::query()
                ->where('firm_id', $firmUser?->firm_id)
                ->where('trust_ledger_id', $record->id)
                ->where('event_type', TrustApprovalEventType::DepositRequested->value)
                ->orderByDesc('created_at')
                ->get()
                ->mapWithKeys(fn (TrustApprovalEvent $event): array => [
                    $event->id => '$'.number_format($event->amount_cents / 100, 2).' — requested '.$event->created_at?->diffForHumans(),
                ])
                ->all();
        }) ?? [];
    }
}
