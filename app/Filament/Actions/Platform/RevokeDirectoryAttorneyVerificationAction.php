<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Services\MarketplaceVerificationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class RevokeDirectoryAttorneyVerificationAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeDirectoryAttorneyVerification';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke Verification');
        $this->icon(Heroicon::OutlinedShieldExclamation);
        $this->color('danger');
        StepUpAuthentication::mergeInto($this, [
            Select::make('dimension')
                ->label('Dimension')
                ->options([
                    VerificationDimension::AttorneyIdentity->value => 'Attorney Identity',
                    VerificationDimension::AttorneyLicense->value => 'Attorney License',
                ])
                ->required(),
            Textarea::make('reason')->label('Reason')->rows(2)->required(),
        ], 'platform_admin');

        $this->action(function (DirectoryAttorney $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceVerificationService $verification): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $decision = $accessPolicy->canManageMarketplaceGovernance($actor);
            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $target = DirectoryAttorney::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That attorney could not be found.')->danger()->send();

                return;
            }

            try {
                $verification->revoke($target, VerificationDimension::from($data['dimension']), $actor, $data['reason']);
                Notification::make()->title('Verification revoked')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not revoke')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
