<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformInternalSalesCommissionsPage — Billing & Commercial Control
 * Plane pass. INTERNAL FirmsVault sales-rep commission tracking: a
 * FirmsVault employee (commission_events.platform_admin_id, a
 * PlatformAdmin) earning commission for closing or expanding a deal.
 *
 * WHY THIS PAGE EXISTS SEPARATELY FROM "Reseller Readiness"
 * --------------------------------------------------------
 * Phase 3 put this table INSIDE PlatformResellersPage, under a nav item
 * labeled "Resellers", with an in-page disclosure explaining that the
 * data was really internal commissions. That disclosure was honest, but
 * the structure still put employee commission data behind a nav label
 * that means "external partner/reseller accounts" — the exact
 * conflation this domain must not make. An internal sales rep is a
 * FirmsVault employee on payroll; a reseller is an external commercial
 * entity with its own account, its own customers, its own pricing, and
 * its own contract. They are different domains with different
 * authorization, payout, and contractual consequences.
 *
 * So the two are now separate pages: this one carries the real data
 * under its real name, and PlatformResellersPage ("Reseller Readiness")
 * carries only the honest statement that no reseller/partner domain
 * exists, with a pointer here for the adjacent-but-different concept.
 * Neither page implies the other's capability.
 *
 * READ-ONLY. CommissionEventService::markPaid()/reverse() exist and are
 * real, but paying out employee commission is a finance/payroll
 * operation whose approval path is not modeled anywhere in this
 * codebase (commission_events has no approver, no payout batch, no
 * approval timestamp — confirmed against its create migration). Nothing
 * on this page mutates a commission event; exposing "Mark Paid" here
 * would assert an approval workflow the backend does not have.
 *
 * `commission_events`/`commission_plans` carry no RLS at all (Global —
 * both are listed on the repository's own RLS coverage registry as
 * exempt tables at this pass's HEAD, re-verified rather than assumed),
 * so an ordinary Eloquent `->query()` table is correct here.
 *
 * FINAL ADMIN RECONCILIATION note: the registry is described rather
 * than named here. Section 26's firewall asserts its mapping-service
 * class names appear in no file under app/Filament, matching raw file
 * contents, so a prose mention alone tripped it once all seven mission
 * branches were combined. This page neither imports nor calls that
 * service, so the honest fix is to stop naming it in a comment rather
 * than to widen that firewall's allowlist. Access is
 * gated by PlatformStaffAccessPolicyService::canAccessPlatformBilling()
 * both on the page (canAccess) and inside the query closure, matching
 * the established pattern — a table whose rows are not tenant-scoped
 * must still be authorization-scoped.
 */
class PlatformInternalSalesCommissionsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Internal Sales Commissions';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 61;

    protected static ?string $title = 'Internal Sales Commissions';

    protected static ?string $slug = 'internal-sales-commissions';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Commission earned by FirmsVault employees on platform deals. This is not reseller or partner '.
            'commission — no reseller/partner account domain exists in this codebase. Read-only.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What this page shows')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'Each row is one CommissionEvent: a commission amount attributed to a FirmsVault sales rep '.
                        'under a CommissionPlan, tied to a platform billing account and, where recorded, to the '.
                        'specific platform invoice or platform payment that triggered it. Amounts are FirmsVault '.
                        'internal compensation — they are never billed to a customer and never appear on a '.
                        'customer invoice.'
                    ),
                    Text::make(
                        'Read-only: no action on this page pays, reverses, blocks, or otherwise changes a '.
                        'commission event. Commission payout has no approval workflow modeled in this codebase — '.
                        'commission_events records a paid_at timestamp but no approver, payout batch, or approval '.
                        'record — so this console does not offer to trigger one.'
                    ),
                    Text::make(
                        'Statuses come from CommissionEventStatus directly: Pending (holding period still running), '.
                        'Payable (eligible), Paid, Blocked (see the blocked reason), Reversed. A commission is not '.
                        'a customer credit, a discount, or a refund, and does not affect what a customer owes.'
                    ),
                ]),
            Section::make('Commission events')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return CommissionEvent::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed) {
                    return CommissionEvent::query()->whereRaw('1 = 0');
                }

                return CommissionEvent::query()
                    ->with(['commissionPlan', 'billingAccount', 'platformAdmin']);
            })
            ->columns([
                TextColumn::make('platformAdmin.name')
                    ->label('Sales rep')
                    ->placeholder('Not attributed')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('billingAccount.name')
                    ->label('Billing account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('commissionPlan.name')
                    ->label('Commission plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Event type')
                    ->badge()
                    ->formatStateUsing(fn (CommissionEventType $state): string => Str::headline($state->value)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CommissionEventStatus $state): string => Str::headline($state->value))
                    ->color(fn (CommissionEventStatus $state): string => match ($state) {
                        CommissionEventStatus::Paid => 'success',
                        CommissionEventStatus::Payable => 'info',
                        CommissionEventStatus::Pending => 'warning',
                        CommissionEventStatus::Blocked, CommissionEventStatus::Reversed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('amount_cents')
                    ->label('Commission amount')
                    ->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('holding_period_ends_at')
                    ->label('Eligible at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid at')
                    ->dateTime()
                    ->placeholder('Not paid')
                    ->sortable(),

                // Secondary/technical detail — off by default so the
                // default table stays inside the 5-8 high-value column
                // budget this console uses everywhere else.
                TextColumn::make('commissionPlan.rate_type')
                    ->label('Rate type')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commissionPlan.rate_value')
                    ->label('Rate value')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blocked_reason')
                    ->label('Blocked reason')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversed_at')
                    ->label('Reversed at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CommissionEventStatus::cases())
                        ->mapWithKeys(fn (CommissionEventStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('event_type')
                    ->label('Event type')
                    ->options(collect(CommissionEventType::cases())
                        ->mapWithKeys(fn (CommissionEventType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
                SelectFilter::make('commission_plan_id')
                    ->label('Commission plan')
                    ->options(fn (): array => CommissionPlan::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('platform_admin_id')
                    ->label('Sales rep')
                    ->options(fn (): array => PlatformAdmin::query()
                        ->whereIn('id', CommissionEvent::query()
                            ->whereNotNull('platform_admin_id')
                            ->distinct()
                            ->pluck('platform_admin_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Filter::make('recorded_between')
                    ->label('Recorded between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->emptyStateHeading('No internal sales commission events recorded')
            ->emptyStateDescription(
                'Commission events are recorded by CommissionEventService when a platform deal qualifies under a '.
                'commission plan. Nothing is created from this page — it is a read-only view.'
            )
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
