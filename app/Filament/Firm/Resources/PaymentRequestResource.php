<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\ActivatePaymentRequestAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\CopyPaymentLinkAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\RevokePaymentRequestAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\ShowQrCodeAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Pages\ListPaymentRequests;
use App\Filament\Firm\Resources\PaymentRequestResource\Pages\ViewPaymentRequest;
use App\Models\Client;
use App\Models\PaymentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * PaymentRequestResource — Payment Link / QR Routing phase, master
 * prompt items 12/17. List + View pages ONLY, mirroring PaymentResource's
 * own "action-based, never a raw editable Create/Edit form bound to a
 * financial-adjacent domain model" discipline — creating, activating,
 * and revoking a PaymentRequest are each their own Action
 * (CreatePaymentRequestAction/ActivatePaymentRequestAction/
 * RevokePaymentRequestAction), every one of them wired directly to
 * PaymentRequestService, never a bare PaymentRequest::create()/update().
 *
 * Entitlement gating: deliberately NONE — mirrors PaymentResource's own
 * documented reasoning exactly (no `payments`/`billing` module_catalog
 * entitlement exists anywhere; authorization here is role-only via
 * PaymentRequestPolicy + PaymentRequestAccessPolicyService).
 */
class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static ?string $slug = 'payment-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Payment Requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable()->placeholder('—'),
                TextColumn::make('purpose')
                    ->label('Purpose')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('amount_rule')
                    ->label('Amount')
                    ->formatStateUsing(function (PaymentRequest $record): string {
                        return match ($record->amount_rule->value) {
                            'fixed' => '$'.number_format(($record->requested_amount_cents ?? 0) / 100, 2),
                            'up_to' => 'Up to '.(($remaining = $record->targetRemainingCents()) !== null ? '$'.number_format($remaining / 100, 2) : '—'),
                            default => 'Custom',
                        };
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'paid' => 'success',
                        'active' => 'info',
                        'draft' => 'gray',
                        'pending_review' => 'warning',
                        'expired', 'revoked', 'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('paid_amount_cents')
                    ->label('Paid')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.user.name')->label('Created By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('purpose')
                    ->options(fn (): array => collect(PaymentRequestPurpose::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => collect(PaymentRequestStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
            ])
            ->recordActions([
                ActivatePaymentRequestAction::make(),
                CopyPaymentLinkAction::make(),
                ShowQrCodeAction::make(),
                RevokePaymentRequestAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentRequests::route('/'),
            'view' => ViewPaymentRequest::route('/{record}'),
        ];
    }
}
