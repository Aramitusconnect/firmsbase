<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MarketplaceIntakeStatus;
use App\Filament\Firm\Resources\MatterResource;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\ConvertMarketplaceProspectService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterType;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ConvertIntakeAction — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 11. Calls ConvertMarketplaceProspectService::convert()
 * directly — creates no Client/Matter itself, only collects the one
 * genuinely required-and-not-derivable input (matter_type_id — no
 * PracticeArea-to-MatterType default mapping exists anywhere in this
 * codebase, matching AddMatterAction's own required Select) and an
 * optional Responsible Attorney, mirroring AddMatterAction's own field
 * shape for those two inputs exactly.
 *
 * Gated on ClientCrmAccessPolicyService::canConvertLead() — confirmed
 * identical role set (FirmOwner/Attorney/Paralegal/LegalAssistant) to
 * MatterCreationAccessPolicyService::canCreateMatter(), so checking
 * the one ceiling covers both halves of what this action actually
 * does (Lead->Client conversion, then Matter creation).
 */
class ConvertIntakeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'convertIntake';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Convert to Client & Matter');
        $this->icon(Heroicon::OutlinedArrowRightCircle);
        $this->color('success');
        $this->modalHeading('Convert Prospect');
        $this->modalDescription('Creates a real Client and a Matter (in Draft status) from this accepted prospect, via the same canonical services every other conversion in this system uses. The Matter can only move to Open afterward via the separate, conflict-gated "Open Matter" action.');
        $this->modalSubmitActionLabel('Convert');
        $this->modalWidth('xl');

        $this->schema([
            Select::make('matter_type_id')
                ->label('Matter Type')
                ->options(function (MarketplaceIntake $record): array {
                    if ($record->practice_area_id === null) {
                        return [];
                    }

                    return MatterType::query()
                        ->where('practice_area_id', $record->practice_area_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->required()
                ->helperText(fn (MarketplaceIntake $record): string => $record->practice_area_id === null
                    // Historical rows only: intakes created before the public
                    // flow asked for a practice area. Matter types hang off a
                    // practice area, so there is nothing to offer — say so
                    // instead of showing an empty required select.
                    ? 'This intake was created before MyAttorney asked visitors what they need help with, so it has no practice area and no matter types can be listed. Contact support to set one.'
                    : 'Only types belonging to this intake\'s own practice area are shown.'),

            Select::make('assigned_attorney_id')
                ->label('Responsible Attorney')
                ->options(function (): array {
                    $firmUser = Auth::user()?->activeFirmUser();

                    if ($firmUser === null) {
                        return [];
                    }

                    return app(TenantContextService::class)->runWithFirmContext(
                        (int) $firmUser->firm_id,
                        fn (): array => FirmUser::query()
                            ->with('user')
                            ->where('role', FirmUserRole::Attorney)
                            ->where('status', FirmUserStatus::Active)
                            ->get()
                            ->mapWithKeys(fn (FirmUser $fu): array => [$fu->user_id => $fu->user?->name ?? "User #{$fu->user_id}"])
                            ->all(),
                    );
                })
                ->searchable()
                ->nullable(),
        ]);

        $this->visible(function (MarketplaceIntake $record): bool {
            if ($record->status !== MarketplaceIntakeStatus::Accepted) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        });

        $this->action(function (array $data, MarketplaceIntake $record, ConvertMarketplaceProspectService $conversion): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                $matter = app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $data, $firmUser, $conversion) {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        return $conversion->convert(
                            $firm,
                            $fresh,
                            (int) $data['matter_type_id'],
                            $firmUser,
                            filled($data['assigned_attorney_id'] ?? null) ? (int) $data['assigned_attorney_id'] : null,
                        );
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not convert prospect')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Prospect converted')->success()->send();

            $this->redirect(MatterResource::getUrl('view', ['record' => $matter]));
        });
    }
}
