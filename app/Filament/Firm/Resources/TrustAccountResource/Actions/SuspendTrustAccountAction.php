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
 * SuspendTrustAccountAction — wired directly to
 * TrustAccountService::suspend(). Visible only for an Active account.
 * See OpenTrustAccountAction's docblock for why this is gated on
 * canApprove() rather than canRequest().
 */
class SuspendTrustAccountAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'suspendTrustAccount';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Suspend');
        $this->icon(Heroicon::OutlinedPauseCircle);
        $this->color('warning');
        $this->requiresConfirmation();
        $this->modalDescription('Suspends this trust account. No new activity should be recorded against it while suspended.');

        $this->visible(function (TrustAccount $record): bool {
            if ($record->status !== TrustAccountStatus::Active) {
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
                    app(TrustAccountService::class)->suspend($firmUser->firm, $fresh);
                    Notification::make()->title('Trust account suspended')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not suspend trust account')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
