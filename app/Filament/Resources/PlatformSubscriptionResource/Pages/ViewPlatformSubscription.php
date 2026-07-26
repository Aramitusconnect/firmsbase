<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformSubscriptionResource\Pages;

use App\Enums\BillingInterval;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Actions\Platform\CancelSubscriptionAction;
use App\Filament\Resources\PlatformSubscriptionResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewPlatformSubscription — the standard Filament ViewRecord page
 * (platform_subscriptions carries no RLS, so ordinary {record}
 * route-model-binding by uuid works with no special handling, unlike
 * ConnectionResource's composite-route workaround for FORCE-RLS
 * tables). The Cancel action is registered here as a header action,
 * mirroring ViewPlatformAdministrator's own "mutations live on the View
 * page" convention.
 */
class ViewPlatformSubscription extends ViewRecord
{
    protected static string $resource = PlatformSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CancelSubscriptionAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')
                ->columns(2)
                ->schema([
                    TextEntry::make('billingAccount.name')->label('Billing account')->placeholder('—'),
                    TextEntry::make('plan.name')->label('Plan')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlatformSubscriptionStatus $state): string => Str::headline($state->value))
                        ->color(fn (PlatformSubscriptionStatus $state): string => match ($state) {
                            PlatformSubscriptionStatus::Active => 'success',
                            PlatformSubscriptionStatus::Trialing => 'info',
                            PlatformSubscriptionStatus::PastDue => 'warning',
                            PlatformSubscriptionStatus::Cancelled, PlatformSubscriptionStatus::Expired => 'danger',
                        }),
                    TextEntry::make('billing_interval')
                        ->label('Billing interval')
                        ->formatStateUsing(fn (BillingInterval $state): string => Str::headline($state->value)),
                    TextEntry::make('current_period_starts_at')->label('Period start')->dateTime(),
                    TextEntry::make('current_period_ends_at')->label('Period end')->dateTime(),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime()->placeholder('—'),
                    IconEntry::make('cancel_at_period_end')->label('Cancel at period end')->boolean(),
                    TextEntry::make('cancelled_at')->label('Cancelled at')->dateTime()->placeholder('—'),
                    TextEntry::make('gateway_subscription_ref')->label('Gateway subscription ref')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                ]),
        ]);
    }
}
