<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustAccountService;
use App\Services\TrustEligibilityService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * OpenTrustAccountAction — "+ Open Trust Account" header action on
 * ListTrustAccounts, wired directly to TrustAccountService::open() —
 * never a bare `TrustAccount::create()`. TrustAccountService::open()
 * itself takes no FirmUser/actor parameter at all and performs no role
 * check of its own (only TrustEligibilityService::assertEligible()) —
 * so this Action's own role gate is the ONLY authorization boundary for
 * who may open a trust account. Deliberately gated on
 * TrustAccessPolicyService::canApprove() (FirmOwner/Attorney only)
 * rather than the wider canRequest() tier: opening the firm's pooled
 * IOLTA account record is a one-time, firm-level setup action, not a
 * routine per-transaction request BillingStaff would ever need to
 * perform — the same reasoning applies to every other
 * account/ledger-lifecycle Action in this module (Suspend/Close/
 * Freeze/Open-Ledger/Start-Reconciliation).
 */
class OpenTrustAccountAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'openTrustAccount';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Open Trust Account');
        $this->modalHeading('Open a Trust Account');
        $this->modalSubmitActionLabel('Open Account');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            TextInput::make('account_name')
                ->label('Account Name')
                ->required()
                ->maxLength(255)
                ->default('Firm IOLTA Trust Account'),
            TextInput::make('bank_name_reference')
                ->label('Bank Reference (record only — no real bank integration)')
                ->maxLength(255),
        ]);

        $this->visible(function (): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                return false;
            }

            return app(TrustEligibilityService::class)->isEligible($firmUser->firm);
        });

        $this->action(function (array $data): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $data): void {
                try {
                    app(TrustAccountService::class)->open(
                        $firmUser->firm,
                        (string) $data['account_name'],
                        filled($data['bank_name_reference'] ?? null) ? (string) $data['bank_name_reference'] : null,
                    );
                    Notification::make()->title('Trust account opened')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not open trust account')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
