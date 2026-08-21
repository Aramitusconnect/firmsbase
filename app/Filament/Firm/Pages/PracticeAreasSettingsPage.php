<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Models\FirmPracticeArea;
use App\Models\PracticeArea;
use App\Services\FirmSettingsAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * PracticeAreasSettingsPage — PRAC-003. `FirmPracticeArea` (per-firm
 * enable/disable join against the global `PracticeArea` catalog) has
 * existed since Phase 2 with no Filament UI anywhere in the Firm panel
 * — a firm could not see or change which practice areas it uses.
 *
 * Modeled directly on `PlaidReclassificationApprovalsPage`'s
 * established "`HasTable` Page, `records()` closure builds plain array
 * rows (not raw Eloquent) from a firm-scoped query, single named
 * `Action` in `recordActions()` re-checks its own gate before writing"
 * shape — the closest existing precedent for "list of items with a
 * per-row inline toggle." Deliberately not `AccountingOverviewPage`'s
 * static required-purposes Section: unlike that fixed checklist, this
 * page's row count depends on how many `PracticeArea` rows exist, which
 * calls for a real table, not a hand-written `Section`/`Text` list.
 *
 * SOURCE OF TRUTH. `practice_areas` (`PracticeArea`) is the global,
 * platform-owned catalog — no `BelongsToTenant`, no RLS, never written
 * here. `firm_practice_areas` (`FirmPracticeArea`) is the firm's own
 * enablement decision — `BelongsToTenant` + FORCE RLS (see
 * `2026_08_20_920001_force_rls_on_firm_practice_areas_table.php`), so
 * every read/write against it is wrapped in
 * `TenantContextService::runWithFirmContext()`, matching
 * `FirmSettingsPage`'s own established convention for exactly this
 * "Livewire AJAX action runs with no ambient tenant context" problem.
 * No new service method is introduced for something this simple — both
 * the read in `table()` and the write in `toggle()` talk to
 * `FirmPracticeArea::query()` directly, exactly like
 * `PlaidUsagePolicyPage::save()` does for
 * `ProviderFirmOperationPolicy`.
 *
 * AUTHORIZATION. Reuses `FirmSettingsAccessPolicyService` — the same
 * access-policy service `FirmSettingsPage` itself uses — rather than
 * inventing a new one: which practice areas a firm uses is exactly the
 * kind of firm-wide configuration that service's docblock already
 * scopes. `canAccess()`/`shouldRegisterNavigation()` gate on
 * `canView()` (every active role may see the list). The `toggle` row
 * Action is `->visible()`-gated AND re-checked inside `toggle()` itself
 * (defense-in-depth, matching every other Action in this panel) on
 * `canManage()` — FirmOwner only, same ceiling as every other
 * firm-wide write on the Firm Settings cluster.
 */
class PracticeAreasSettingsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Practice Areas';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 22;

    protected static ?string $title = 'Practice Areas';

    protected static ?string $slug = 'practice-areas-settings';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return collect();
                }

                // Defense-in-depth: canAccess() gates navigation/route
                // entry, but this records() closure is the actual data
                // query — re-checked independently, matching
                // PlaidReclassificationApprovalsPage's own established
                // precedent for this exact "don't trust nav gating
                // alone" concern.
                if (! app(FirmSettingsAccessPolicyService::class)->canView($firmUser->role)) {
                    return collect();
                }

                $firmPracticeAreas = app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    fn (): Collection => FirmPracticeArea::query()
                        ->where('firm_id', $firmUser->firm_id)
                        ->get()
                        ->keyBy('practice_area_id'),
                );

                return PracticeArea::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(function (PracticeArea $practiceArea) use ($firmPracticeAreas): array {
                        /** @var FirmPracticeArea|null $firmPracticeArea */
                        $firmPracticeArea = $firmPracticeAreas->get($practiceArea->id);

                        return [
                            'id' => $practiceArea->id,
                            'name' => $practiceArea->name,
                            'code' => $practiceArea->code,
                            'description' => $practiceArea->description,
                            'is_enabled' => (bool) ($firmPracticeArea?->is_enabled ?? false),
                            'enabled_at' => $firmPracticeArea?->enabled_at?->toDayDateTimeString(),
                            'disabled_at' => $firmPracticeArea?->disabled_at?->toDayDateTimeString(),
                        ];
                    });
            })
            ->columns([
                TextColumn::make('name')->label('Practice Area'),
                TextColumn::make('code')->label('Code'),
                TextColumn::make('description')->label('Description')->limit(60)->placeholder('—'),
                TextColumn::make('is_enabled')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('enabled_at')->label('Enabled At')->placeholder('—'),
                TextColumn::make('disabled_at')->label('Disabled At')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (array $record): string => $record['is_enabled'] ? 'Disable' : 'Enable')
                    ->color(fn (array $record): string => $record['is_enabled'] ? 'danger' : 'success')
                    ->visible(fn (): bool => static::canManagePracticeAreas())
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->toggle((int) $record['id'], (bool) $record['is_enabled'])),
            ])
            ->emptyStateHeading('No active practice areas configured.')
            ->paginated(false);
    }

    private function toggle(int $practiceAreaId, bool $currentlyEnabled): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);
        abort_unless(app(FirmSettingsAccessPolicyService::class)->canManage($firmUser->role), 403);

        $newState = ! $currentlyEnabled;
        $now = now();

        app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($firmUser, $practiceAreaId, $newState, $now): void {
                FirmPracticeArea::query()->updateOrCreate(
                    [
                        'firm_id' => $firmUser->firm_id,
                        'practice_area_id' => $practiceAreaId,
                    ],
                    $newState
                        ? ['is_enabled' => true, 'enabled_at' => $now]
                        : ['is_enabled' => false, 'disabled_at' => $now],
                );
            },
        );

        Notification::make()
            ->title($newState ? 'Practice area enabled' : 'Practice area disabled')
            ->success()
            ->send();
    }

    private static function canManagePracticeAreas(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canManage($firmUser->role);
    }
}
