<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\RevokeConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Pages\ListCommunicationConsents;
use App\Filament\Firm\Resources\CommunicationConsentResource\Pages\ViewCommunicationConsent;
use App\Filament\Firm\Resources\CommunicationConsentResource\RelationManagers\ConsentEventsRelationManager;
use App\Models\Client;
use App\Models\CommunicationConsent;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * CommunicationConsentResource — Firm Feature Manifest §16: "Communication
 * consent — READY. ConsentService — solid, append-only audit trail, safe
 * to expose read/manual-entry UI today independent of dispatch going
 * live." This is the firm-wide list/search view (search/filter by
 * client, channel, status, date range) — the client-scoped counterpart
 * lives on ClientResource\CommunicationConsentsRelationManager (see that
 * class's own docblock). Both share the same Capture/Revoke Actions and
 * write path (`ConsentService::capture()`/`revoke()`), never a
 * duplicated model write.
 *
 * List + View pages ONLY — no Create/Edit page (mirrors PaymentResource's
 * own "Action-based, never Form-backed Create/Edit" ruling): `ConsentService`
 * is the sole writer of `communication_consents`, so there is nothing to
 * create/edit via a generic model-bound form. "Record Consent" is
 * exclusively CaptureConsentAction (a header Action calling
 * `ConsentService::capture()`); "Revoke" is exclusively
 * RevokeConsentAction (a row Action calling `ConsentService::revoke()`,
 * visible only on Granted rows).
 *
 * Navigation group: "Communications" (a new top-level nav group, not
 * folded into "Clients & Matters"). Decision, documented per this
 * mission's own instruction: although consent rows are client-scoped,
 * the Firm Feature Manifest treats Communications (§16) as its own
 * domain distinct from Client/CRM (§1) — Document Chase and any future
 * client-notification-adjacent UI (still §16) will likely land in this
 * same group, and keeping it separate avoids the "Clients & Matters"
 * group growing into a catch-all for anything that merely references a
 * Client.
 *
 * Authorization: standard Laravel Policy (App\Policies\
 * CommunicationConsentPolicy) for viewAny()/view(); Capture/Revoke are
 * checked directly against ConsentAccessPolicyService inside their own
 * Actions (no create()/update() policy methods exist — see
 * CommunicationConsentPolicy's own docblock for why, mirroring
 * PaymentPolicy).
 */
class CommunicationConsentResource extends Resource
{
    protected static ?string $model = CommunicationConsent::class;

    protected static ?string $slug = 'communication-consents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Communication Consent';

    protected static string|\UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Recorded')->dateTime()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable()->placeholder('—'),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'granted' => 'success',
                        'declined', 'revoked' => 'danger',
                        'expired' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('currently_granted')
                    ->label('Currently Contactable')
                    ->boolean()
                    ->state(fn (CommunicationConsent $record): bool => $record->isGranted()),
                TextColumn::make('consent_text_version')->label('Text Version')->placeholder('—'),
                TextColumn::make('granted_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('revoked_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('captured_via')->label('Captured Via')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('channel')
                    ->options(fn (): array => collect(ConsentChannel::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => collect(ConsentStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('recorded_from')->label('Recorded from')->native(false),
                        DatePicker::make('recorded_until')->label('Recorded until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['recorded_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['recorded_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                RevokeConsentAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ConsentEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommunicationConsents::route('/'),
            'view' => ViewCommunicationConsent::route('/{record}'),
        ];
    }
}
