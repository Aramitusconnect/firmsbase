<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\RetentionGovernanceRegistryService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformRetentionGovernancePage — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Retention module. READ-ONLY, policy-configuration
 * visibility only — see the Phase 4 architecture map §B.2 and the
 * mission's own resolved decision for this module.
 *
 * Two clearly separated sections, mirroring PlatformResellersPage's
 * established "prominent honest disclosure + real, differently-scoped
 * adjacent data, both clearly labeled" template:
 *  1. Integration Retention Governance Registry
 *     (RetentionGovernanceRegistryService::categories()) — a pure,
 *     read-only, declarative registry describing every integration
 *     retention category's config-driven window, enforcing
 *     class/method, and status. No sweep-HISTORY is shown here or
 *     anywhere on this page — RetentionSweepAuditLogger writes flat
 *     log-file lines only (storage/logs/integration-retention-sweep.log),
 *     never a DB row, so a structured sweep-history list cannot be built
 *     against real, queryable data today. Building fragile log-tailing
 *     into this page, or inventing a new DB audit table, are both
 *     explicitly out of this phase's scope (mission's own resolved
 *     decision) — this limitation is disclosed prominently below, not
 *     silently worked around.
 *  2. Retention Policies (`retention_policies`) — platform-default
 *     (firm_id null) vs firm-specific override rows, via
 *     RetentionPolicyService's own effective-policy model. This table
 *     carries NO row level security at all — confirmed a `Hybrid`
 *     classification in the repo's own live RLS coverage inventory, but a
 *     genuinely UNCOVERED one (not in EXEMPT_TABLES). This is disclosed
 *     here as an INHERITED coverage gap this page did not create and is
 *     not asserting is safe-by-design — a plain Eloquent ->query() is
 *     used below because no RLS exists to work around (there is
 *     structurally no per-firm-loop needed), not because this table's
 *     lack of RLS has been separately reviewed and approved.
 *
 * `RetentionPolicyService::supersede()` is a real, safe mutation but is
 * NOT exposed here — it carries no authorization logic of its own and
 * superseding a live policy is a consequential governance action better
 * scoped as a separate, explicitly-authorized future pass (mirrors this
 * mission's general "don't invent an action beyond what was explicitly
 * scoped" discipline).
 */
class PlatformRetentionGovernancePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Retention';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $title = 'Retention';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->limitationSection(),
            $this->registrySection(),
            Section::make('Retention Policies')
                ->description('Platform-default (Firm: —) and firm-specific override policies. This table carries no row level security — a disclosed, inherited coverage gap, not asserted safe-by-design.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    private function limitationSection(): Section
    {
        return Section::make('Sweep History Is Not Shown Here')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->schema([
                Text::make(
                    'RetentionSweepAuditLogger writes flat log-file lines only (storage/logs/integration-retention-sweep.log) — '.
                    'no database table records sweep history. This page deliberately does not build fragile log-tailing into a Filament '.
                    'page as a substitute, and does not add a new database audit table to work around it — that would be a separate, '.
                    'human-approved backend task. What is shown below is real, DB-backed policy CONFIGURATION visibility only.'
                )->color('warning'),
            ]);
    }

    private function registrySection(): Section
    {
        $categories = app(RetentionGovernanceRegistryService::class)->categories();

        $rows = collect($categories)->map(function (array $entry, string $category): string {
            $default = $entry['current_default'] ?? null;
            $defaultLabel = $default === null ? 'not configured' : (string) $default;

            return sprintf(
                '%s — status: %s%s — config: %s — default: %s',
                Str::headline($category),
                Str::headline($entry['status']),
                $entry['legal_hold_coverage_unresolved'] ? ' (legal hold coverage unresolved)' : '',
                $entry['config_key'] ?? '—',
                $defaultLabel,
            );
        })->values()->all();

        return Section::make('Integration Retention Governance Registry')
            ->description('Read-only, declarative registry (RetentionGovernanceRegistryService) — every integration retention category\'s config-driven window and enforcing class. No sweep-history rows.')
            ->schema(array_map(fn (string $line): Text => Text::make($line), $rows));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return RetentionPolicy::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed) {
                    return RetentionPolicy::query()->whereRaw('1 = 0');
                }

                return RetentionPolicy::query()->with('firm');
            })
            ->filters([
                SelectFilter::make('record_type')
                    ->options(collect(RetentionRecordType::cases())
                        ->mapWithKeys(fn (RetentionRecordType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(RetentionPolicyStatus::cases())
                        ->mapWithKeys(fn (RetentionPolicyStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm.name')->label('Firm')->placeholder('Platform default')->searchable(),
                TextColumn::make('record_type')
                    ->badge()
                    ->formatStateUsing(fn (RetentionRecordType $state): string => Str::headline($state->value)),
                TextColumn::make('document_category')->label('Document category')->placeholder('—'),
                TextColumn::make('retention_period_days')->label('Retention (days)')->placeholder('—')->alignEnd(),
                IconColumn::make('is_permanent')->label('Permanent')->boolean(),
                IconColumn::make('allows_client_replacement')->label('Allows replacement')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RetentionPolicyStatus $state): string => Str::headline($state->value))
                    ->color(fn (RetentionPolicyStatus $state): string => match ($state) {
                        RetentionPolicyStatus::Active => 'success',
                        RetentionPolicyStatus::Draft => 'warning',
                        RetentionPolicyStatus::Superseded, RetentionPolicyStatus::Archived => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('effective_at')->label('Effective at')->dateTime()->placeholder('—'),
            ])
            ->emptyStateHeading('No retention policies found')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
