<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Actions\Platform\ResendFirmOwnerInvitationAction;
use App\Filament\Resources\FirmResource;
use App\Filament\Resources\PlanResource;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\FirmSeatCapacityService;
use App\Services\TenantContextService;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewFirm — read-only detail view over Firm's real fillable columns
 * only. No generic Edit page exists (see FirmResource's own docblock).
 *
 * Platform Firm Provisioning workflow addition: ResendFirmOwnerInvitationAction
 * is the one header action registered here — a narrow, explicit
 * state-machine action (re-dispatch the owner's setup email), never
 * arbitrary field editing. Its own ->visible()/authorization-inside-the-
 * closure discipline is documented on that class.
 *
 * "Commercial / License" section (Admin audit follow-up): read-only
 * only, deliberately. `FirmSeatCapacityService` (Firm Feature Manifest
 * §12's flat per-firm seat model) is the ONLY source of truth consulted
 * here for purchased/used/remaining seats — never a duplicated ad-hoc
 * query. There is no reusable domain SERVICE for adjusting
 * `purchased_seats` today — the only existing write path is
 * `firms:report-missing-purchased-seats --apply`'s own inline console-
 * command logic, not a Service class this page could call the way
 * every other mutation in this panel routes through one. Adding a Filament
 * Action here would mean either reimplementing that write logic a second
 * time (drifting from the command's own idempotency/force/conflict
 * rules) or bypassing it — both worse than staying read-only until a
 * proper `FirmLicenseService`-style seat-adjustment method exists. See
 * the accompanying report for this gap.
 *
 * Plan/License-status entries deliberately do NOT use Filament's
 * automatic `license.plan.name` / `license.license_status` dot-path
 * relationship resolution: that resolution accesses the lazy `license`
 * relation with no tenant context active (the Admin panel has no
 * ambient per-request tenant-context middleware, unlike the Firm
 * panel), and `firm_licenses` is FORCE ROW LEVEL SECURITY protected --
 * an unwrapped read silently returns nothing. `resolvedLicense()` below
 * wraps the read in `TenantContextService::runWithFirmContext()`,
 * mirroring how `FirmSeatCapacityService` already self-wraps every one
 * of its own reads for the exact same reason.
 */
class ViewFirm extends ViewRecord
{
    protected static string $resource = FirmResource::class;

    private ?FirmLicense $resolvedLicenseCache = null;

    private ?int $resolvedLicenseCacheFirmId = null;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ResendFirmOwnerInvitationAction::make(),
        ];
    }

    private function resolvedLicense(Firm $record): ?FirmLicense
    {
        if ($this->resolvedLicenseCacheFirmId === $record->id) {
            return $this->resolvedLicenseCache;
        }

        $this->resolvedLicenseCacheFirmId = $record->id;

        return $this->resolvedLicenseCache = app(TenantContextService::class)->runWithFirmContext(
            $record,
            fn () => $record->license()->with('plan')->first(),
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Firm')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('legal_name')->placeholder('—'),
                    TextEntry::make('activation_status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('customer_type')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('deployment_mode')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('primary_country')->label('Country')->placeholder('—'),
                    TextEntry::make('primary_state')->label('State/Province')->placeholder('—'),
                    TextEntry::make('default_timezone')->label('Timezone')->placeholder('—'),
                    TextEntry::make('default_currency')->label('Currency')->placeholder('—'),
                    TextEntry::make('data_region')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Commercial / License')
                ->columns(3)
                ->schema([
                    TextEntry::make('plan_name')
                        ->label('Plan')
                        ->state(fn (Firm $record): ?string => $this->resolvedLicense($record)?->plan?->name)
                        ->placeholder('No plan assigned')
                        ->url(function (Firm $record): ?string {
                            $plan = $this->resolvedLicense($record)?->plan;

                            return $plan ? PlanResource::getUrl('view', ['record' => $plan]) : null;
                        }),
                    TextEntry::make('license_status')
                        ->label('License status')
                        ->badge()
                        ->state(fn (Firm $record) => $this->resolvedLicense($record)?->license_status)
                        ->placeholder('No license')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('purchased_seats')
                        ->label('Purchased seats')
                        ->state(fn (Firm $record): ?int => app(FirmSeatCapacityService::class)->purchasedSeats($record))
                        ->placeholder('Not configured / Unset'),
                    TextEntry::make('used_seats')
                        ->label('Seats used')
                        ->state(fn (Firm $record): int => app(FirmSeatCapacityService::class)->usedSeats($record)),
                    TextEntry::make('remaining_seats')
                        ->label('Seats remaining')
                        ->state(fn (Firm $record): ?int => app(FirmSeatCapacityService::class)->remainingSeats($record))
                        ->placeholder('—'),
                ]),
        ]);
    }
}
