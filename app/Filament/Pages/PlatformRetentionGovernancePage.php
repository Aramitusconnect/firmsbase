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

    /**
     * The scheduled sweep cadence, kept identical to the schedule entry
     * bootstrap/app.php registers. Displayed as configuration only.
     */
    private const SWEEP_SCHEDULE_DESCRIPTION = 'daily (integrations:retention:sweep)';

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
                ->description(
                    'Effective-dated policy rows. A row with no firm is the platform default; a row with a firm is that '.
                    'firm\'s override, and RetentionPolicyService resolves the override ahead of the default. Read-only '.
                    'here: superseding a live policy is a consequential governance action and is not exposed on this page.'
                )
                ->schema([
                    Text::make(
                        'SECURITY DISCLOSURE — retention_policies carries no row level security at all: neither ENABLE '.
                        'nor FORCE, and no policy. The repository\'s own coverage inventory classifies it Hybrid '.
                        '(firm_id null = platform default, firm_id set = firm override), so it is a genuinely UNCOVERED '.
                        'tenant-relevant table rather than an approved exempt/global one. The plain query below works '.
                        'because there is no RLS to satisfy — not because this gap has been reviewed and accepted. '.
                        'Closing it needs an owner-approved schema/policy change that must keep platform-default rows '.
                        'readable under an active firm context; it is deliberately NOT performed from UI work.'
                    )->color('danger'),
                    EmbeddedTable::make(),
                ]),
        ]);
    }

    private function limitationSection(): Section
    {
        return Section::make('Sweep History Is Not Shown Here')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->schema([
                Text::make(
                    'RetentionSweepAuditLogger writes flat log-file lines only (storage/logs/integration-retention-sweep.log) — '.
                    'no database table records sweep history, and no scheduler-run table records whether a scheduled sweep '.
                    'executed. This page deliberately does not build fragile log-tailing into a Filament page as a substitute, '.
                    'and does not add a new database audit table to work around it — that would be a separate, human-approved '.
                    'backend task. What is shown below is real, DB-backed policy CONFIGURATION visibility only.'
                )->color('warning'),
                Text::make(
                    'Consequence for this page: "never swept" and "zero failures" cannot be distinguished here, so neither is '.
                    'claimed. Scheduled cadence is '.self::SWEEP_SCHEDULE_DESCRIPTION.' — that is configuration, not evidence '.
                    'that any sweep ran.'
                )->color('gray'),
            ]);
    }

    /**
     * The registry rendered as one labelled block per category rather
     * than a single run-on line each, so an operator can read a
     * category's window, enforcing service and hold-coverage verdict
     * without parsing prose.
     *
     * Every field shown is backed by RetentionGovernanceRegistryService.
     * Fields a reader might reasonably expect and which this deployment
     * genuinely cannot supply — last verified, last sweep, next sweep —
     * are stated as unavailable rather than left blank or dashed, since
     * a dash would read as "none" instead of "not recorded".
     */
    private function registrySection(): Section
    {
        $registry = app(RetentionGovernanceRegistryService::class);
        $components = [];

        foreach ($registry->categories() as $category => $entry) {
            $components[] = Text::make(Str::headline($category))->weight('bold');

            $components[] = Text::make('Status: '.$this->statusLabel($entry['status']))
                ->color($this->statusColor($entry['status']));

            $components[] = Text::make('Legal hold coverage: '.$this->holdCoverageLabel($entry))
                ->color($entry['legal_hold_coverage_unresolved'] ? 'danger' : 'success');

            $components[] = Text::make('Governing tables: '.implode(', ', $entry['tables']))->color('gray');
            $components[] = Text::make('Config key: '.($entry['config_key'] ?? 'None — no retention window applies'))->color('gray');
            $components[] = Text::make('Configured window: '.$this->windowLabel($entry))->color('gray');
            $components[] = Text::make('Enforcing service: '.$entry['enforcing'])->color('gray');
            $components[] = Text::make('Last verified / last sweep / next sweep: not recorded (no durable sweep evidence)')->color('gray');
            $components[] = Text::make($entry['notes'])->color('gray')->size('sm');
        }

        return Section::make('Integration Retention Governance Registry')
            ->description(
                'Read-only, declarative registry (RetentionGovernanceRegistryService) — every integration retention '.
                'category\'s config-driven window, enforcing class, and legal hold coverage verdict. Policy source for '.
                'every category here is Config Default or Fail Safe: these windows are read from config() by the sweep '.
                'code itself, never from a retention_policies row, so no platform-default/firm-override provenance '.
                'exists to display. No sweep-history rows.'
            )
            ->collapsible()
            ->schema($components);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT => 'Configured (default enforced by a live sweep)',
            RetentionGovernanceRegistryService::STATUS_CONFIGURED_PLACEHOLDER => 'Configured (disclosed placeholder, not compliance-anchored)',
            RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE => 'Not configured — fail safe (sweep no-ops, never guesses a window)',
            RetentionGovernanceRegistryService::STATUS_OUT_OF_SCOPE_SNAPSHOT => 'Out of scope (snapshot table with no independent age)',
            default => 'Not yet applicable (config key exists, no live sweep enforces it)',
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT => 'success',
            RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE => 'warning',
            default => 'gray',
        };
    }

    /**
     * §30: every category gets an explicit verdict. "Unresolved" is a
     * named blocker with its cause, never a passive banner.
     *
     * @param  array{legal_hold_coverage_unresolved: bool, status: string}  $entry
     */
    private function holdCoverageLabel(array $entry): string
    {
        if ($entry['legal_hold_coverage_unresolved']) {
            return 'UNRESOLVED — RetentionSweepJob does not call LegalHoldService::checkHold() for this category, '
                .'and no resolution layer maps a swept row back to a client_id/matter_id a hold check could use. '
                .'Closing this requires a backend resolution layer, not a UI change.';
        }

        if ($entry['status'] === RetentionGovernanceRegistryService::STATUS_OUT_OF_SCOPE_SNAPSHOT) {
            return 'Not applicable — no independent retention window, so no disposition a hold could block.';
        }

        return 'Not applicable — this category holds no client/matter-attributable legal data that a hold would preserve.';
    }

    /**
     * @param  array{current_default: mixed, config_key: ?string, status: string}  $entry
     */
    private function windowLabel(array $entry): string
    {
        if ($entry['config_key'] === null) {
            return 'None — a retention window is structurally meaningless for this table';
        }

        if ($entry['current_default'] === null) {
            if ($entry['status'] === RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE) {
                return 'Not configured — ships with no default on purpose; the sweep no-ops rather than guessing';
            }

            return 'Multiple windows under one entry — read the documented config() keys directly';
        }

        $unit = str_contains($entry['config_key'], '_hours') ? 'hours' : 'days';

        return $entry['current_default'].' '.$unit;
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
