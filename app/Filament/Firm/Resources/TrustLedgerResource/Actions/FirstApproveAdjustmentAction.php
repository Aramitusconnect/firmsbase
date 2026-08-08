<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustApprovalEventType;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustHighRiskAdjustmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * FirstApproveAdjustmentAction — "First-Approve Adjustment" header
 * action on ViewTrustLedger, wired directly to
 * TrustHighRiskAdjustmentService::firstApprove(). See
 * ApproveDepositAction's docblock for why pending requests are surfaced
 * via a scoped Select rather than a relation-backed table.
 */
class FirstApproveAdjustmentAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'firstApproveAdjustment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('First-Approve Adjustment');
        $this->modalHeading('First Approval — High-Risk Adjustment');
        $this->modalDescription('A second, DIFFERENT approver must still confirm this before any ledger entry is posted.');
        $this->modalSubmitActionLabel('First-Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('warning');

        $this->schema([
            Select::make('approval_event_id')
                ->label('Pending Adjustment Request')
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
                    Notification::make()->title('Could not approve adjustment')->body('The selected request could not be found.')->danger()->send();

                    return;
                }

                try {
                    app(TrustHighRiskAdjustmentService::class)->firstApprove($firmUser->firm, $event, $firmUser);
                    Notification::make()->title('Adjustment first-approved')->body('A different approver must now provide the second approval.')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not first-approve adjustment')->body($e->getMessage())->danger()->send();
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
                ->where('event_type', TrustApprovalEventType::AdjustmentRequested->value)
                ->orderByDesc('created_at')
                ->get()
                ->mapWithKeys(fn (TrustApprovalEvent $event): array => [
                    $event->id => '$'.number_format($event->amount_cents / 100, 2).' — requested '.$event->created_at?->diffForHumans(),
                ])
                ->all();
        }) ?? [];
    }
}
