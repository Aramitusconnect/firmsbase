<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Filament\Firm\Resources\InvoiceResource\Actions\AddManualChargeAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\ApproveInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SendInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SubmitInvoiceForReviewAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\VoidInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Firm\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Firm\Resources\InvoiceResource\RelationManagers\LinesRelationManager;
use App\Filament\Firm\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
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
 * InvoiceResource — Firm Feature Manifest §6: "Invoices — UNSAFE if
 * exposed as raw CRUD... Totals are always derived/cached — never
 * expose status/totals as editable form fields; every mutation must be
 * a named Action calling the service." List + View pages ONLY — no
 * Create/Edit page (mirrors PaymentResource's/Trust §7's own
 * "Action-based, never Form-backed Create/Edit" ruling, for the exact
 * same reason: `Invoice` is a canonical financial document this whole
 * mission's rule #4 forbids exposing as raw editable form fields).
 *
 * No `form()` method exists on this Resource at all — every mutation
 * (drafting, adding a charge, submitting for review, approving,
 * sending, voiding) is one of the dedicated InvoiceResource\Actions\*
 * Actions, each calling exactly one InvoiceDraftingService method —
 * see each Action's own docblock. "+ Draft from Time Entries" /
 * "+ Create Flat-Fee Invoice" are two distinct header Actions (rather
 * than one Action with a type toggle) because InvoiceDraftingService's
 * two creation methods take genuinely different argument shapes
 * (`array<TimeEntry> $timeEntries` vs `string $description, int
 * $amountCents`) — a shared toggle-form would need to fake one set of
 * fields into disabled/hidden state depending on the other, which is
 * exactly the kind of trap DocumentRequestResource's own docblock
 * warns against ("never a generic Edit form"); two small, honest forms
 * are clearer than one form pretending to be two.
 *
 * `status`/`subtotal_cents`/`total_cents`/`amount_paid_cents` are
 * NEVER editable form fields anywhere in this Resource or its Actions
 * — InvoiceDraftingService is the exclusive writer of every one of
 * them (see that service's own docblock: "amount fields are recomputed
 * from invoice_lines every time, never hand-set elsewhere").
 *
 * Authorization: standard Laravel Policy (App\Policies\InvoicePolicy)
 * for viewAny()/view(); every Action additionally checks
 * BillingAccessPolicyService directly (no create()/update() Policy
 * method exists — see InvoicePolicy's own docblock for why).
 *
 * Entitlement gating: deliberately NONE — Invoices/Payment Plans carry
 * no module_catalog code anywhere in EntitlementService or any
 * *EntitlementPolicyService (confirmed by direct source read), matching
 * PaymentResource's own identical, already-documented decision.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Invoice')->formatStateUsing(fn ($state): string => "#{$state}")->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('invoice_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'paid' => 'success',
                        'sent', 'approved' => 'info',
                        'partially_paid' => 'warning',
                        'void', 'written_off' => 'gray',
                        'refunded' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('amount_paid_cents')
                    ->label('Paid')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('due_at')->label('Due')->dateTime()->placeholder('—')->sortable(),
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
                    ->options(fn (): array => collect(InvoiceStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('invoice_type')
                    ->label('Type')
                    ->options(fn (): array => collect(InvoiceType::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
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
                AddManualChargeAction::make(),
                SubmitInvoiceForReviewAction::make(),
                ApproveInvoiceAction::make(),
                SendInvoiceAction::make(),
                VoidInvoiceAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
