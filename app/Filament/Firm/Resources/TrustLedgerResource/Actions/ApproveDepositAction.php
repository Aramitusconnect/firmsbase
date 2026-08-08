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
 * ApproveDepositAction — "Approve Pending Deposit" header action on
 * ViewTrustLedger, wired directly to TrustDepositService::approveDeposit().
 * There is no dedicated trust_deposit_requests table (the deposit
 * request/approve/deny lifecycle lives entirely in trust_approval_events
 * — see TrustDepositService's own docblock), and TrustLedger has no
 * `approvalEvents()` relation defined on the model (a Filament model
 * file this task is forbidden from modifying), so — unlike
 * Transfer/RefundRequestsRelationManager, which DO have real HasMany
 * relations to bind a table to — pending deposit requests are surfaced
 * here via a scoped Select picker instead of a relation-backed table.
 * Options are restricted to this ledger's own DepositRequested events,
 * queried directly against trust_approval_events with an explicit
 * `firm_id`/`trust_ledger_id` filter (that table deliberately does not
 * use BelongsToTenant — see TrustApprovalEvent's own model docblock —
 * so this explicit filter is not a redundant convenience, it is one of
 * the only two tenant guards this query has).
 *
 * Gated on TrustAccessPolicyService::canApprove() — FirmOwner/Attorney
 * only; BillingStaff may request a deposit but never approve one.
 */
class ApproveDepositAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'approveDeposit';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve Deposit');
        $this->modalHeading('Approve a Pending Deposit Request');
        $this->modalSubmitActionLabel('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

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
                    Notification::make()->title('Could not approve deposit')->body('The selected request could not be found.')->danger()->send();

                    return;
                }

                try {
                    app(TrustDepositService::class)->approveDeposit($firmUser->firm, $event, $firmUser);
                    Notification::make()->title('Deposit approved')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not approve deposit')->body($e->getMessage())->danger()->send();
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
