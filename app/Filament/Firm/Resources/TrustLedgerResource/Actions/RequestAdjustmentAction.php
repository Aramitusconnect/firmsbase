<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustHighRiskAdjustmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * RequestAdjustmentAction — "Request High-Risk Adjustment" header
 * action on ViewTrustLedger, wired directly to
 * TrustHighRiskAdjustmentService::requestAdjustment() (the first of the
 * request -> firstApprove -> secondApprove Adjustment Actions). A
 * signed "direction" + unsigned "magnitude" pair is used rather than a
 * single signed numeric field, since a bare `TextInput::numeric()`
 * cannot enforce "no zero" cleanly alongside a natural credit/debit
 * toggle for the person requesting it.
 */
class RequestAdjustmentAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'requestAdjustment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Adjustment');
        $this->modalHeading('Request a High-Risk Trust Adjustment');
        $this->modalDescription('Requires two DIFFERENT approvers (both FirmOwner/Attorney) before any ledger entry is posted.');
        $this->modalSubmitActionLabel('Submit Request');
        $this->modalWidth('lg');
        $this->icon(Heroicon::OutlinedExclamationTriangle);
        $this->color('danger');

        $this->schema([
            Select::make('direction')
                ->label('Direction')
                ->options(['credit' => 'Credit (increase ledger balance)', 'debit' => 'Debit (decrease ledger balance)'])
                ->required(),
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->required(),
            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (TrustLedger $record): array => self::firmScoped(fn () => Matter::query()
                    ->where('client_id', $record->client_id)
                    ->get()
                    ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                    ->all()) ?? [])
                ->searchable()
                ->nullable(),
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(3),
        ]);

        $this->visible(function (TrustLedger $record): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
        });

        $this->action(function (array $data, TrustLedger $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this ledger.')->danger()->send();

                    return;
                }

                $fresh = TrustLedger::query()->where('id', $record->id)->firstOrFail();

                $matter = filled($data['matter_id'] ?? null)
                    ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $fresh->client_id)->first()
                    : null;

                $magnitudeCents = (int) round(((float) $data['amount']) * 100);
                $delta = $data['direction'] === 'debit' ? -1 * $magnitudeCents : $magnitudeCents;

                try {
                    app(TrustHighRiskAdjustmentService::class)->requestAdjustment(
                        $firmUser->firm,
                        $fresh,
                        $firmUser,
                        $delta,
                        (string) $data['reason'],
                        $matter,
                    );
                    Notification::make()->title('Adjustment requested')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not request adjustment')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
