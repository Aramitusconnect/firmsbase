<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceCorrectionService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ResolveCorrectionRequestAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Upgraded by the SuperAdmin console
 * professionalization mission (MYAT5): MarketplaceCorrectionService::
 * resolve()'s own $fieldChanges parameter has existed since checkpoint
 * 11 and was never passed by this action — the original docblock
 * explicitly deferred it to avoid "a large dynamic-field modal". This
 * mission's own spec explicitly asks for a current-vs-requested
 * comparison, so the deferred capability is wired now: one optional
 * text field per PUBLIC_PROFILE_FIELDS entry, pre-filled with the
 * firm's CURRENT value, left unchanged fields are dropped before
 * calling resolve() so only genuinely edited fields become a change
 * (resolve() itself also intersects against the same allowlist, so
 * this is defense in depth, not the only guard).
 */
class ResolveCorrectionRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolveCorrectionRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve');
        $this->icon(Heroicon::OutlinedCheckBadge);
        $this->color('success');
        $this->schema([
            TextInput::make('display_name')->label('Display Name')->maxLength(255),
            TextInput::make('phone')->label('Phone')->maxLength(255),
            TextInput::make('website')->label('Website')->url()->maxLength(255),
            TextInput::make('public_email')->label('Public Email')->email()->maxLength(255),
            Textarea::make('description')->label('Description')->rows(2),
            Textarea::make('resolution_notes')->label('Resolution notes')->required()->rows(2),
        ]);
        $this->fillForm(function (DirectoryCorrectionRequest $record): array {
            $firm = DirectoryFirm::query()->find($record->directory_firm_id);

            return [
                'display_name' => $firm?->display_name,
                'phone' => $firm?->phone,
                'website' => $firm?->website,
                'public_email' => $firm?->public_email,
                'description' => $firm?->description,
            ];
        });
        $this->modalDescription('Any field changed below is applied to the listing and recorded as a versioned change. Leave a field unchanged to make no edit to it.');
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryCorrectionRequest $record): bool => $record->state === CorrectionState::Approved);

        $this->action(function (DirectoryCorrectionRequest $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceCorrectionService $corrections): void {
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

            $target = DirectoryCorrectionRequest::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That request could not be found.')->danger()->send();

                return;
            }

            $firm = DirectoryFirm::query()->find($target->directory_firm_id);
            $fieldChanges = [];
            foreach (['display_name', 'phone', 'website', 'public_email', 'description'] as $field) {
                $submitted = $data[$field] ?? null;
                $current = $firm?->{$field};
                if (filled($submitted) && $submitted !== $current) {
                    $fieldChanges[$field] = $submitted;
                }
            }

            try {
                $corrections->resolve($target, $actor, $data['resolution_notes'], $fieldChanges);
                Notification::make()->title('Correction request resolved')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not resolve request')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
