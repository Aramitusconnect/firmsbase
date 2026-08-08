<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\Actions;

use App\Enums\TrustAccountStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustAccount;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustAccountService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * CloseTrustAccountAction — wired directly to TrustAccountService::close().
 * Visible for an Active or Suspended account (Closed is terminal — the
 * service itself has no "reopen" method, so Closed is a dead end by
 * design; this Action is simply hidden once already Closed).
 */
class CloseTrustAccountAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'closeTrustAccount';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Close');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Closes this trust account. This is terminal — there is no "reopen" path.');

        $this->visible(function (TrustAccount $record): bool {
            if (! in_array($record->status, [TrustAccountStatus::Active, TrustAccountStatus::Suspended], true)) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (TrustAccount $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this trust account.')->danger()->send();

                    return;
                }

                $fresh = TrustAccount::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustAccountService::class)->close($firmUser->firm, $fresh);
                    Notification::make()->title('Trust account closed')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not close trust account')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
