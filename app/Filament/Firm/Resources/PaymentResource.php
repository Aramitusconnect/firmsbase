<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Filament\Firm\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Firm\Resources\PaymentResource\Pages\ViewPayment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * PaymentResource — Firm Feature Manifest §6, cross-cutting finding
 * #11: "Manual client payments already work end-to-end
 * (ManualPaymentService), idempotent... the safest, highest-value
 * Billing feature to expose first." List + View pages ONLY — no
 * Create/Edit page (mirrors FirmIntegrationResource's/Trust §7's own
 * "Action-based, never Form-backed Create/Edit" ruling, here for the
 * strongest possible reason: `Payment`/`ManualPaymentRecord` are the
 * canonical financial ledger rows this whole mission's rule #4
 * forbids exposing as raw editable form fields). Recording a new
 * payment is exclusively the "Record Payment" header Action
 * (RecordPaymentAction) — never a CreateRecord page bound to the
 * Payment model.
 *
 * No `form()` method exists on this Resource at all — there is
 * nothing to create/edit via a generic model-bound form; ViewPayment
 * defines its own read-only Infolist instead.
 *
 * Entitlement gating: deliberately NONE. Confirmed by direct source
 * read — Manual Client Payments is classified plain READY in the
 * manifest (not PAID ADD-ON), and no `payments`/`billing` module_catalog
 * code exists anywhere in EntitlementService/AccountingEntitlementPolicyService
 * (which gates `expenses` only) or any other *EntitlementPolicyService.
 * This mirrors TimeEntryResource's own identical, already-documented
 * decision for the same reason. Authorization is role-only, via
 * standard Laravel Policy (App\Policies\PaymentPolicy) for
 * viewAny()/view(), and PaymentAccessPolicyService::canRecordPayment()
 * checked directly inside RecordPaymentAction/RecordClientPaymentAction
 * (no `create()`/`update()` Policy methods exist — see PaymentPolicy's
 * own docblock for why).
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payments';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Recorded')->dateTime()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable()->placeholder('—'),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('payment_classification')
                    ->label('Classification')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'succeeded' => 'success',
                        'initiated', 'pending', 'classified' => 'info',
                        'blocked', 'failed', 'disputed', 'reversed' => 'danger',
                        'refunded', 'partially_refunded' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('external_reference')->label('External Ref.')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recordedBy.name')->label('Recorded By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('matter_id')
                    ->label('Matter')
                    ->options(fn (): array => Matter::query()
                        ->with('client')
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [
                            $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                        ])
                        ->all()),
                SelectFilter::make('invoice_id')
                    ->label('Invoice')
                    ->options(fn (): array => Invoice::query()->pluck('id', 'id')->mapWithKeys(fn ($id) => [$id => "Invoice #{$id}"])->all()),
                SelectFilter::make('payment_method')
                    ->label('Method')
                    ->options(fn (): array => collect(ManualPaymentMethod::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('payment_classification')
                    ->label('Classification')
                    ->options(fn (): array => collect(PaymentClassification::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => collect(PaymentStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
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
                Filter::make('amount_cents')
                    ->schema([
                        TextInput::make('amount_min')->label('Min amount')->numeric()->minValue(0)->prefix('$'),
                        TextInput::make('amount_max')->label('Max amount')->numeric()->minValue(0)->prefix('$'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['amount_min'] ?? null), fn (Builder $q) => $q->where('amount_cents', '>=', (int) round(((float) $data['amount_min']) * 100)))
                            ->when(filled($data['amount_max'] ?? null), fn (Builder $q) => $q->where('amount_cents', '<=', (int) round(((float) $data['amount_max']) * 100)));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
