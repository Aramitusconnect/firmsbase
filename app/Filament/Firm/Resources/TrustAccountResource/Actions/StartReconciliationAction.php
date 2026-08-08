<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\Actions;

use App\Enums\TrustReconciliationStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustAccount;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * StartReconciliationAction — "Start Reconciliation" header action on
 * ReconciliationsRelationManager (a tab on ViewTrustAccount), wired
 * directly to TrustReconciliationService::run(). The result
 * (Balanced/Discrepancy) is only ever DISPLAYED via the resulting
 * notification and the read-only reconciliations table row this
 * creates — there is no "fix"/auto-correct action anywhere in this
 * class or elsewhere in this module (project rule: a Discrepancy is a
 * durable fact requiring a deliberate, separately-authorized, human-
 * reviewed TrustHighRiskAdjustmentService adjustment afterward, never
 * an automatic side effect of running a reconciliation).
 */
class StartReconciliationAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'startReconciliation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Start Reconciliation');
        $this->modalHeading('Start a Trust Reconciliation');
        $this->modalDescription('Compares the system-cached trust balances for this account against a manually asserted bank statement balance. A discrepancy, if found, is recorded as-is — it is never auto-corrected here.');
        $this->modalSubmitActionLabel('Run Reconciliation');
        $this->icon(Heroicon::OutlinedCalculator);
        $this->color('primary');

        $this->schema([
            DatePicker::make('period_start')->label('Period Start')->required()->native(false),
            DatePicker::make('period_end')->label('Period End')->required()->native(false),
            TextInput::make('asserted_bank_balance')
                ->label('Asserted Bank Balance')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->required()
                ->helperText('The real trust bank account balance from your bank statement for this period, entered manually.'),
        ]);

        $this->visible(function (RelationManager $livewire): bool {
            $account = $livewire->getOwnerRecord();

            if (! $account instanceof TrustAccount) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $account->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (array $data, RelationManager $livewire): void {
            $account = $livewire->getOwnerRecord();
            $firmUser = self::activeFirmUser();

            if (
                $firmUser === null
                || ! $account instanceof TrustAccount
                || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)
            ) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $account, $data): void {
                if ((int) $firmUser->firm_id !== (int) $account->firm_id) {
                    Notification::make()->title('You do not have access to this trust account.')->danger()->send();

                    return;
                }

                $fresh = TrustAccount::query()->where('id', $account->id)->firstOrFail();

                try {
                    $reconciliation = app(TrustReconciliationService::class)->run(
                        $firmUser->firm,
                        $fresh,
                        $firmUser,
                        Carbon::parse($data['period_start']),
                        Carbon::parse($data['period_end']),
                        (int) round(((float) $data['asserted_bank_balance']) * 100),
                    );

                    if ($reconciliation->status === TrustReconciliationStatus::Balanced) {
                        Notification::make()->title('Reconciliation complete — Balanced')->success()->send();
                    } else {
                        Notification::make()
                            ->title('Reconciliation complete — Discrepancy found')
                            ->body('Discrepancy: $'.number_format($reconciliation->discrepancy_cents / 100, 2).'. This has been recorded as-is; it was not auto-corrected. Review and, if warranted, submit a separate high-risk adjustment.')
                            ->warning()
                            ->send();
                    }
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not run reconciliation')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
