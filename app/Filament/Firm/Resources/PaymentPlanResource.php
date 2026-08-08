<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\PaymentPlanStatus;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\ActivatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\CancelPaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\MarkPaymentPlanDefaultedAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\RenegotiatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Pages\ListPaymentPlans;
use App\Filament\Firm\Resources\PaymentPlanResource\Pages\ViewPaymentPlan;
use App\Filament\Firm\Resources\PaymentPlanResource\RelationManagers\InstallmentsRelationManager;
use App\Models\Client;
use App\Models\Matter;
use App\Models\PaymentPlan;
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
 * PaymentPlanResource — Firm Feature Manifest §6: "Payment Plans —
 * UNSAFE if exposed as raw CRUD... renegotiate() creates a new plan
 * row, never mutates in place; markDefaulted() is explicit human-only,
 * never automatic." List + View pages ONLY — no Create/Edit page,
 * mirroring InvoiceResource's/PaymentResource's own ruling.
 *
 * No `form()` method exists on this Resource — every mutation
 * (create/activate/renegotiate/cancel/markDefaulted) is one of the
 * dedicated PaymentPlanResource\Actions\* Actions, each calling exactly
 * one PaymentPlanService method. `status`/`total_cents` are NEVER
 * editable form fields anywhere in this Resource or its Actions —
 * PaymentPlanService is the exclusive writer of both (see that
 * service's own docblock: "total_cents is always the sum of
 * installment amounts... recomputed here, never a hand-set running
 * balance").
 *
 * "Renegotiate" is represented honestly as producing a brand NEW
 * PaymentPlan row: RenegotiatePaymentPlanAction redirects to the new
 * plan's own ViewPaymentPlan page after success (never staying on/
 * re-rendering the old, now-Renegotiated plan as if it had simply been
 * edited), and this table surfaces `supersedes.id`/a superseded-by
 * badge so the lineage is visible at a glance — see
 * RenegotiatePaymentPlanAction's own docblock.
 *
 * Authorization: standard Laravel Policy (App\Policies\
 * PaymentPlanPolicy) for viewAny()/view(); every Action additionally
 * checks BillingAccessPolicyService directly.
 */
class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static ?string $slug = 'payment-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $navigationLabel = 'Payment Plans';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Plan')->formatStateUsing(fn ($state): string => "#{$state}")->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'completed' => 'primary',
                        'draft' => 'gray',
                        'paused' => 'warning',
                        'renegotiated' => 'info',
                        'defaulted', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('installment_count')->label('Installments'),
                TextColumn::make('supersedes.id')
                    ->label('Supersedes')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : "Plan #{$state}")
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('activated_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('status')
                    ->options(fn (): array => collect(PaymentPlanStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')->label('Created from')->native(false),
                        DatePicker::make('created_until')->label('Created until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
                Filter::make('total_cents')
                    ->schema([
                        TextInput::make('amount_min')->label('Min total')->numeric()->minValue(0)->prefix('$'),
                        TextInput::make('amount_max')->label('Max total')->numeric()->minValue(0)->prefix('$'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['amount_min'] ?? null), fn (Builder $q) => $q->where('total_cents', '>=', (int) round(((float) $data['amount_min']) * 100)))
                            ->when(filled($data['amount_max'] ?? null), fn (Builder $q) => $q->where('total_cents', '<=', (int) round(((float) $data['amount_max']) * 100)));
                    }),
            ])
            ->recordActions([
                ActivatePaymentPlanAction::make(),
                RenegotiatePaymentPlanAction::make(),
                CancelPaymentPlanAction::make(),
                MarkPaymentPlanDefaultedAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlans::route('/'),
            'view' => ViewPaymentPlan::route('/{record}'),
        ];
    }
}
