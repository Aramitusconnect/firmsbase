<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationUsageSummaryService;
use App\Services\IntegrationEntitlementPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * IntegrationUsagePage — Checkpoint 10 (frozen-design-post-security-
 * review.md §6, §11.2; agent-10h-architecture-security-review.md §5,
 * §11.2). Standalone Filament Page, deliberately NOT nested under
 * FirmIntegrationResource: `assertCanViewUsage()`'s ceiling
 * (FirmOwner, BillingStaff) and `assertCanView()`'s ceiling (FirmOwner,
 * Attorney, Paralegal, LegalAssistant) are genuinely disjoint —
 * BillingStaff cannot see connection detail at all, so nesting this
 * page under the resource would make usage data structurally
 * unreachable for them. Gated by a custom canAccess() calling
 * IntegrationAccessPolicyService::canViewUsage() directly — NOT via
 * FirmIntegrationPolicy (unmodified; usage is a firm-wide aggregate
 * view with no natural single-record binding a Laravel Policy method
 * is shaped for).
 *
 * Usage-record write-path wiring (recordOnce() call sites inside
 * PullSyncJob/PushSyncJob/webhook receipt) is explicitly OUT of
 * Checkpoint 10 scope (frozen design §6) — `integration_usage_records`
 * is genuinely empty in every environment today. This page is built in
 * full against the REAL table anyway, with an honest empty state
 * ("No usage has been recorded yet" — never "$0 used," which would
 * imply a real zero rather than an absence of measurement).
 *
 * Uses `Filament\Tables\Concerns\InteractsWithTable` directly (a plain
 * page, not a Resource ListRecords page) with the table's data source
 * built via `Table::records()` from
 * `IntegrationUsageSummaryService::summariesForFirm()`'s aggregate DTOs
 * — never a raw `IntegrationUsageRecord` query bound directly to the
 * table (this model's columns are all SAFE per 10D §1.5, but the
 * service-aggregated DTO is the correct read-model layer regardless).
 * `content()` embeds the table into this page's schema via
 * `EmbeddedTable::make()`, using Filament's own generic
 * `filament-panels::pages.page` view (`{{ $this->content }}`) — no new
 * Blade view file is required or created.
 */
class IntegrationUsagePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Integration Usage';

    protected static ?string $title = 'Integration Usage';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canViewUsage($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return collect();
                }

                // Re-checked here too (not merely at canAccess()/mount
                // time) — hydrateCanAuthorizeAccess() already re-runs
                // canAccess() on every Livewire request, but the table's
                // own data resolution stays independently scoped to the
                // CURRENT request's acting firm regardless.
                app(IntegrationEntitlementPolicyService::class)->assertEnabled($firmUser->firm);
                app(IntegrationAccessPolicyService::class)->assertCanViewUsage($firmUser);

                return app(IntegrationUsageSummaryService::class)
                    ->summariesForFirm((int) $firmUser->firm_id)
                    ->map(fn ($summary): array => [
                        'id' => implode(':', [
                            $summary->firmIntegrationId,
                            $summary->providerKey,
                            $summary->capability,
                            $summary->operationType,
                            $summary->direction?->value ?? 'none',
                            $summary->unit,
                        ]),
                        'connection_label' => $summary->connectionLabel,
                        'provider_key' => $summary->providerKey,
                        'capability' => $summary->capability,
                        'operation_type' => $summary->operationType,
                        'direction' => $summary->direction?->value,
                        'total_quantity' => $summary->totalQuantity,
                        'unit' => $summary->unit,
                        'first_occurred_at' => $summary->firstOccurredAt,
                        'last_occurred_at' => $summary->lastOccurredAt,
                    ]);
            })
            ->columns([
                TextColumn::make('connection_label')->label('Connection'),
                TextColumn::make('provider_key')->label('Provider'),
                TextColumn::make('capability'),
                TextColumn::make('operation_type')->label('Operation'),
                TextColumn::make('direction')->placeholder('—'),
                TextColumn::make('total_quantity')->label('Quantity')->alignEnd(),
                TextColumn::make('unit'),
                TextColumn::make('first_occurred_at')->label('First recorded')->dateTime(),
                TextColumn::make('last_occurred_at')->label('Last recorded')->dateTime(),
            ])
            ->emptyStateHeading('No usage has been recorded yet')
            ->emptyStateDescription(
                'Usage evidence is written as sync, webhook, and outbox operations occur. Once activity begins, '.
                "it will appear here grouped by connection, provider, and capability, subject to this firm's ".
                'configured retention period.'
            )
            ->paginated(false);
    }
}
