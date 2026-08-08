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
 * SecondApproveAdjustmentAction — "Second-Approve Adjustment" header
 * action on ViewTrustLedger, wired directly to
 * TrustHighRiskAdjustmentService::secondApprove(). Rule #5's
 * distinct-approver UX guard: `pendingRequestOptions()` below
 * unconditionally excludes any AdjustmentFirstApproved event whose
 * `actor_firm_user_id` matches the CURRENTLY logged-in FirmUser — the
 * same person who performed the first approval structurally cannot
 * even select their own first approval as the one to second-approve,
 * and if that leaves zero eligible events the whole Action is hidden
 * rather than shown-then-rejected. This is UX only, belt-and-suspenders
 * on top of the real enforcement: TrustAccessPolicyService::
 * assertDistinctApprovers(), called unconditionally inside
 * TrustHighRiskAdjustmentService::secondApprove() itself, which is what
 * actually prevents this regardless of what the UI does or does not
 * offer.
 */
class SecondApproveAdjustmentAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'secondApproveAdjustment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Second-Approve Adjustment');
        $this->modalHeading('Second Approval — High-Risk Adjustment');
        $this->modalDescription('Posts the adjustment ledger entry. You cannot second-approve an adjustment you first-approved yourself.');
        $this->modalSubmitActionLabel('Second-Approve & Post');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('success');

        $this->schema([
            Select::make('approval_event_id')
                ->label('Adjustment Awaiting Second Approval')
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
                    Notification::make()->title('Could not second-approve adjustment')->body('The selected request could not be found.')->danger()->send();

                    return;
                }

                if ((int) $event->actor_firm_user_id === (int) $firmUser->id) {
                    Notification::make()
                        ->title('Could not second-approve adjustment')
                        ->body('You cannot second-approve an adjustment you first-approved yourself. A different FirmOwner/Attorney must provide the second approval.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    app(TrustHighRiskAdjustmentService::class)->secondApprove($firmUser->firm, $event, $firmUser);
                    Notification::make()->title('Adjustment second-approved and posted')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not second-approve adjustment')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    /**
     * Excludes any event whose actor is the currently logged-in
     * FirmUser — the distinct-approver UX guard (rule #5).
     *
     * @return array<int, string>
     */
    private static function pendingRequestOptions(TrustLedger $record): array
    {
        return self::firmScoped(function () use ($record): array {
            $firmUser = self::activeFirmUser();

            return TrustApprovalEvent::query()
                ->where('firm_id', $firmUser?->firm_id)
                ->where('trust_ledger_id', $record->id)
                ->where('event_type', TrustApprovalEventType::AdjustmentFirstApproved->value)
                ->where('actor_firm_user_id', '!=', $firmUser?->id)
                ->orderByDesc('created_at')
                ->get()
                ->mapWithKeys(fn (TrustApprovalEvent $event): array => [
                    $event->id => '$'.number_format($event->amount_cents / 100, 2).' — first-approved '.$event->created_at?->diffForHumans(),
                ])
                ->all();
        }) ?? [];
    }
}
