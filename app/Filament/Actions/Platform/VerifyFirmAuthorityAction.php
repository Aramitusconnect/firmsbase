<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceVerificationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * VerifyFirmAuthorityAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. "Verify authority/identity" is one of the
 * mission spec's own explicitly named high-risk actions — step-up
 * gated. A deliberate SuperAdmin decision, never inferred from claim
 * approval (section 19) — grants the FirmAuthorityVerified badge via
 * MarketplaceBadgeService's own real read of directory_verifications.
 */
class VerifyFirmAuthorityAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'verifyFirmAuthority';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Verify Firm Authority');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('success');
        StepUpAuthentication::mergeInto($this, [
            Select::make('source')
                ->label('Verification source')
                ->options(collect(VerificationSource::cases())->mapWithKeys(fn (VerificationSource $s) => [$s->value => Str::headline($s->value)])->all())
                ->required(),
            Textarea::make('notes')->label('Notes (internal)')->rows(2)->nullable(),
        ], 'platform_admin');
        $this->modalDescription('Grants the "Firm Authority Verified" badge based on reviewed evidence.');

        $this->visible(function (DirectoryFirm $record): bool {
            return ! app(MarketplaceVerificationService::class)->isVerified($record, VerificationDimension::FirmAuthority);
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

            $verification->verify($target, VerificationDimension::FirmAuthority, $actor, VerificationSource::from($data['source']), null, $data['notes'] ?? null);
            Notification::make()->title('Firm authority verified')->success()->send();
        });
    }
}
