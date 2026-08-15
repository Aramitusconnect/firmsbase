<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PlaidItemOversightResource\Pages\ListPlaidItemOversight;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\PlatformPlaidItemDirectoryService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlaidItemOversightResource — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Cross-firm,
 * List-only administrative oversight over Plaid `firm_integrations`
 * rows, mirroring `ConnectionResource`'s own
 * `PlatformConnectionDirectoryService`-shaped `->records(closure)`
 * pattern (`firm_integrations` carries permanent FORCE ROW LEVEL
 * SECURITY with no cross-firm-read policy). REDACTION DISCIPLINE
 * (binding, stated once — checkpoint4-combined-design.md §9.4): never
 * queries `financial_evidence_*` fact/snapshot tables, and no column
 * renders a dollar amount, account number, merchant name, or balance
 * figure belonging to an individual transaction.
 */
class PlaidItemOversightResource extends Resource
{
    protected static ?string $model = FirmIntegration::class;

    protected static ?string $slug = 'plaid-item-oversight';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Plaid Items';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 12;

    /**
     * Operator-facing labels (§60): this Resource's underlying model is
     * the generic FirmIntegration, but the rows it lists are Plaid Items
     * specifically (the read service filters to the Plaid provider), so
     * the singular/plural labels are pinned here — otherwise Filament
     * derives them from the model and renders "Firm Integration"/"Firm
     * Integrations" in the breadcrumb and record-title positions while
     * the navigation says "Plaid Items".
     */
    protected static ?string $modelLabel = 'Plaid Item';

    protected static ?string $pluralModelLabel = 'Plaid Items';

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $onlyFirmId = null;

                if (! empty($filters['firm_uuid']['value'] ?? null)) {
                    $onlyFirmId = Firm::query()->where('uuid', $filters['firm_uuid']['value'])->value('id');
                }

                return app(PlatformPlaidItemDirectoryService::class)->listAll($admin, $onlyFirmId);
            })
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? '')),
                TextColumn::make('display_label')->label('Connection'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'scope_insufficient', 'reauthorization_required' => 'warning',
                        'error' => 'danger',
                        'disconnected' => 'gray',
                        default => 'gray',
                    }),
                // ->state() rather than ->formatStateUsing(?array): the
                // same Filament single-element-array unwrapping that
                // crashed Provider Health applies here too — a Plaid Item
                // with exactly ONE requested product would pass a string
                // where ?array was declared.
                TextColumn::make('products')
                    ->label('Products')
                    ->state(function (array $record): string {
                        $products = $record['requested_capabilities_json'] ?? null;

                        if (! is_array($products) || $products === []) {
                            return 'No products recorded';
                        }

                        return implode(', ', $products);
                    })
                    ->limit(60),
                TextColumn::make('health_summary_state')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? IntegrationDisplay::NOT_CHECKED : Str::headline($state))
                    ->color(fn (?string $state): string => IntegrationDisplay::healthColor($state)),
                TextColumn::make('connected_at')->label('Connected At')->dateTime()->placeholder('Never connected'),
            ])
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'reauthorization_required' => 'Reauthorization required',
                        'disconnected' => 'Disconnected',
                        'error' => 'Error',
                    ]),
            ])
            ->emptyStateHeading('No Plaid Items connected')
            ->emptyStateDescription('Items appear here after a firm completes Plaid authorization in its own panel. This console never creates a Plaid Item, and never renders a Plaid access token.')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaidItemOversight::route('/'),
        ];
    }
}
