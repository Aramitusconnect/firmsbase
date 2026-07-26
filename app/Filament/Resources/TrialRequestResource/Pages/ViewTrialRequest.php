<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrialRequestResource\Pages;

use App\Enums\TrialRequestStatus;
use App\Filament\Actions\Platform\ActivateTrialRequestAction;
use App\Filament\Actions\Platform\ConvertTrialRequestAction;
use App\Filament\Actions\Platform\ExpireTrialRequestAction;
use App\Filament\Actions\Platform\ProvisionTrialRequestAction;
use App\Filament\Resources\TrialRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewTrialRequest — the standard Filament ViewRecord page
 * (trial_requests carries no RLS, ordinary {record} route-model-binding
 * by uuid). Provision/Activate/Convert/Expire live here as header
 * actions, mirroring ViewPlatformAdministrator's own "mutations live on
 * the View page" convention.
 */
class ViewTrialRequest extends ViewRecord
{
    protected static string $resource = TrialRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProvisionTrialRequestAction::make(),
            ActivateTrialRequestAction::make(),
            ConvertTrialRequestAction::make(),
            ExpireTrialRequestAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trial Request')
                ->columns(2)
                ->schema([
                    TextEntry::make('opportunity.platformLead.company_name')->label('Opportunity')->placeholder('—'),
                    TextEntry::make('organization.name')->label('Organization')->placeholder('Not yet provisioned'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (TrialRequestStatus $state): string => Str::headline($state->value))
                        ->color(fn (TrialRequestStatus $state): string => match ($state) {
                            TrialRequestStatus::Active => 'success',
                            TrialRequestStatus::Converted => 'success',
                            TrialRequestStatus::Requested, TrialRequestStatus::Provisioned => 'info',
                            TrialRequestStatus::Expired, TrialRequestStatus::Cancelled => 'danger',
                        }),
                    TextEntry::make('requested_at')->label('Requested')->dateTime()->placeholder('—'),
                    TextEntry::make('provisioned_at')->label('Provisioned')->dateTime()->placeholder('—'),
                    TextEntry::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
                    TextEntry::make('converted_at')->label('Converted')->dateTime()->placeholder('—'),
                ]),
        ]);
    }
}
