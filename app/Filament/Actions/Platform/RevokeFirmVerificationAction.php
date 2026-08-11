<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceVerificationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RevokeFirmVerificationAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Same high-risk classification as
 * VerifyFirmAuthorityAction — step-up gated.
 */
class RevokeFirmVerificationAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeFirmVerification';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke Verification');
        $this->icon(Heroicon::OutlinedShieldExclamation);
        $this->color('danger');
        StepUpAuthentication::mergeInto($this, [
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ], 'platform_admin');
        $this->modalDescription('Revokes the "Firm Authority Verified" badge.');

        $this->visible(function (DirectoryFirm $record): bool {
            return app(MarketplaceVerificationService::class)->isVerified($record, VerificationDimension::FirmAuthority);
        });

        $this->action(function (DirectoryFirm $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceVerificationService $verification): void {
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

            $target = DirectoryFirm::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That listing could not be found.')->danger()->send();

                return;
            }

            try {
                $verification->revoke($target, VerificationDimension::FirmAuthority, $actor, $data['reason']);
                Notification::make()->title('Verification revoked')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not revoke verification')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
