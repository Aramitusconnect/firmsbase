<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustApprovalEventType;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Matter;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustDepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * PostDepositAction — "Post Approved Deposit" header action on
 * ViewTrustLedger, wired directly to TrustDepositService::post() (the
 * third of the three request -> approve -> post Deposit Actions). Only
 * UNCONSUMED DepositApproved events (no trust_ledger_entries row
 * already references this exact approval event id — matching
 * TrustDepositService::post()'s own duplicate-post guard) are offered.
 * The matter for post() is deliberately never re-selected by the user
 * here — it is derived directly from the chosen approval event's own
 * `matter_id` (post() itself throws if the given matter doesn't
 * exactly match the approval event), which removes an entire class of
 * "picked the wrong matter" user error rather than merely validating
 * against it after the fact.
 *
 * Gated on canApprove() — see this module's report for why "post" is
 * treated as approver-tier even though TrustDepositService::post()
 * itself performs no role check of its own.
 */
class PostDepositAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'postDeposit';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Post Deposit');
        $this->modalHeading('Post an Approved Deposit');
        $this->modalDescription('Posts the trust_ledger_entries row for an already-approved deposit and recomputes the cached balance.');
        $this->modalSubmitActionLabel('Post');
        $this->icon(Heroicon::OutlinedBanknotes);
        $this->color('success');

        $this->schema([
            Select::make('approval_event_id')
                ->label('Approved Deposit Awaiting Posting')
                ->options(fn (TrustLedger $record): array => self::unpostedApprovedOptions($record))
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

            return filled(self::unpostedApprovedOptions($record));
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

                $fresh = TrustLedger::query()->where('id', $record->id)->firstOrFail();

                $event = TrustApprovalEvent::query()
                    ->where('id', $data['approval_event_id'])
                    ->where('firm_id', $firmUser->firm_id)
                    ->where('trust_ledger_id', $fresh->id)
                    ->first();

                if ($event === null) {
                    Notification::make()->title('Could not post deposit')->body('The selected approval could not be found.')->danger()->send();

                    return;
                }

                $matter = $event->matter_id !== null ? Matter::query()->where('id', $event->matter_id)->first() : null;

                try {
                    app(TrustDepositService::class)->post($firmUser->firm, $fresh, $event, $matter);
                    Notification::make()->title('Deposit posted')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not post deposit')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    /**
     * @return array<int, string>
     */
    private static function unpostedApprovedOptions(TrustLedger $record): array
    {
        return self::firmScoped(function () use ($record): array {
            $firmUser = self::activeFirmUser();

            $postedEventIds = TrustLedgerEntry::query()
                ->where('trust_ledger_id', $record->id)
                ->whereNotNull('trust_approval_event_id')
                ->pluck('trust_approval_event_id');

            return TrustApprovalEvent::query()
                ->where('firm_id', $firmUser?->firm_id)
                ->where('trust_ledger_id', $record->id)
                ->where('event_type', TrustApprovalEventType::DepositApproved->value)
                ->whereNotIn('id', $postedEventIds)
                ->orderByDesc('created_at')
                ->get()
                ->mapWithKeys(fn (TrustApprovalEvent $event): array => [
                    $event->id => '$'.number_format($event->amount_cents / 100, 2).' — approved '.$event->created_at?->diffForHumans(),
                ])
                ->all();
        }) ?? [];
    }
}
