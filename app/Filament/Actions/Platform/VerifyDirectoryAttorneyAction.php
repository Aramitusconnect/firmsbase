<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
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
use Illuminate\Support\Str;

/**
 * VerifyDirectoryAttorneyAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT3). Attorneys have TWO
 * SuperAdmin-verifiable dimensions (AttorneyIdentity, AttorneyLicense
 * — see VerificationDimension's own docblock), unlike DirectoryFirm's
 * one (FirmAuthority) that VerifyFirmAuthorityAction targets. Rather
 * than duplicating that action twice for two fixed dimensions, this
 * one action lets the admin pick which dimension they're verifying —
 * same MarketplaceVerificationService::verify() call either way,
 * which is already generic over Model $verifiable (confirmed by its
 * own resolveLinkedTenantFirm() explicitly handling DirectoryAttorney).
 * Same step-up gating as VerifyFirmAuthorityAction (mission's own
 * explicitly named high-risk action).
 */
class VerifyDirectoryAttorneyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'verifyDirectoryAttorney';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Verify');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('success');
        StepUpAuthentication::mergeInto($this, [
            Select::make('dimension')
                ->label('Dimension')
                ->options([
                    VerificationDimension::AttorneyIdentity->value => 'Attorney Identity',
                    VerificationDimension::AttorneyLicense->value => 'Attorney License',
                ])
                ->required(),
            Select::make('source')
                ->label('Verification source')
                ->options(collect(VerificationSource::cases())->mapWithKeys(fn (VerificationSource $s) => [$s->value => Str::headline($s->value)])->all())
                ->required(),
            Textarea::make('notes')->label('Notes (internal)')->rows(2)->nullable(),
        ], 'platform_admin');
        $this->modalDescription('Grants a verification badge based on reviewed evidence (bar record, ID, etc).');

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

            $verification->verify(
                $target,
                VerificationDimension::from($data['dimension']),
                $actor,
                VerificationSource::from($data['source']),
                null,
                $data['notes'] ?? null,
            );
            Notification::make()->title('Verification recorded')->success()->send();
        });
    }
}
