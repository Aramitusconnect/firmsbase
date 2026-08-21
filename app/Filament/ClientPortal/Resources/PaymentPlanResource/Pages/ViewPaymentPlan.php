<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\PaymentPlanResource\Pages;

use App\Filament\ClientPortal\Resources\PaymentPlanResource;
use App\Models\ClientPortalUser;
use App\Models\PaymentPlan;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ViewPaymentPlan (Client Portal) — PORTAL-003. Per-record
 * authorization boundary — re-checks
 * PaymentPlanResource::isVisibleToPortalUser() directly, never trusting
 * PaymentPlanResource::getEloquentQuery()'s list-level filter alone
 * (the identical "list is UX filter, resolve step is the boundary"
 * split this panel's other resources already establish).
 *
 * Read-only Infolist: plan status, total, installment count, currency,
 * and the plan-level lifecycle timestamps. Internal operational detail
 * that isn't appropriate to surface to a client directly — e.g. plan
 * renegotiation/supersession lineage — is deliberately left off this
 * page; the client-relevant detail (per-installment amounts/due
 * dates/paid state) lives on the "Installments" relation manager
 * instead.
 */
class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var PaymentPlan $record */
        $record = parent::resolveRecord($key);

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        abort_unless(
            $portalUser !== null && PaymentPlanResource::isVisibleToPortalUser($record, $portalUser),
            403,
        );

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Plan')
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label('Plan')->formatStateUsing(fn ($state): string => "#{$state}"),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "Invoice #{$state}"),
                    TextEntry::make('status')
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
                    TextEntry::make('total_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('installment_count')->label('Installments'),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('activated_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('defaulted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Created At')->dateTime(),
                ]),
        ]);
    }
}
