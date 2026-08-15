<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntitlementOverrideResource\Pages;

use App\Enums\EntitlementSource;
use App\Filament\Resources\EntitlementOverrideResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\Configuration\EntitlementResolutionTraceService;
use App\Services\PlatformEntitlementOverrideDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewEntitlementOverride — a custom Resource page mirroring
 * ViewConflict's exact shape (composite firmUuid/id route, TOCTOU-safe
 * fresh read on every render).
 *
 * Configuration Control Plane: this page answers mission section 4's
 * questions for one (firm, module) pair, in three sections that map
 * directly onto the mission's required Effective / Overrides / History
 * split (section 38):
 *
 *   EFFECTIVE — what access the firm actually has right now, and which
 *   source produced it.
 *
 *   RESOLUTION TRACE — every source in canonical precedence order,
 *   what each says, and why the losers lost. This replaces the old
 *   page's bare "Precedence: 4" line, which required the operator to
 *   know the ranking by heart and told them nothing about the other
 *   sources (section 42: raw numeric precedence stays secondary).
 *
 *   HISTORY — the append-only firm_entitlement_events trail for the
 *   whole module, so a change of winner is explicable (section 47).
 *
 * Every value shown comes from the canonical resolver via
 * EntitlementResolutionTraceService; this page performs no precedence
 * logic of its own.
 */
class ViewEntitlementOverride extends Page
{
    protected static string $resource = EntitlementOverrideResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Entitlement';

    public string $firmUuid = '';

    public int $id = 0;

    public function mount(string $firmUuid, int $id): void
    {
        $this->firmUuid = $firmUuid;
        $this->id = $id;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $row = app(PlatformEntitlementOverrideDirectoryService::class)->findEntitlement($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
            abort(404);
        }
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        $firm = Firm::findByUuid($this->firmUuid);
        $directory = app(PlatformEntitlementOverrideDirectoryService::class);

        try {
            $row = $directory->findEntitlement($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null || $firm === null) {
            return $schema->components([
                Text::make('This entitlement record could not be found.')->color('danger'),
            ]);
        }

        $traceService = app(EntitlementResolutionTraceService::class);
        $trace = $traceService->trace($firm, $row['module_code']);

        return $schema->components([
            Section::make('Effective access')
                ->description('What this firm actually has right now.')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$trace['firm_name']),
                    Text::make('Module: '.$trace['module_name'].' ('.$trace['module_code'].')'),
                    Text::make('Effective state: '.$trace['effective_label']),
                    Text::make('Winning source: '.$trace['winning_source_label']),
                ]),

            Section::make('Resolution trace')
                ->description('Every entitlement source in canonical precedence order, highest first. Resolved by EntitlementService — the single canonical resolver.')
                ->schema(array_map(
                    fn (array $line): Text => Text::make($this->traceLine($line)),
                    $trace['rows'],
                )),

            Section::make('This record')
                ->description('The specific firm_entitlements row you navigated to.')
                ->columns(2)
                ->schema([
                    Text::make('Source: '.($row['source'] === null
                        ? 'Unknown'
                        : $traceService->sourceLabel(EntitlementSource::from($row['source'])))),
                    Text::make('Configured state: '.($row['enabled'] ? 'Enabled' : 'Disabled')),
                    Text::make('Window: '.$row['window_state']),
                    Text::make('Starts at: '.($row['starts_at']?->toDayDateTimeString() ?? 'No start date')),
                    // Mission section 45: permanence must be
                    // unmistakable, never an ambiguous dash.
                    Text::make('Ends at: '.($row['ends_at']?->toDayDateTimeString() ?? 'Permanent — until revoked')),
                    Text::make('Last updated: '.($row['updated_at']?->toDayDateTimeString() ?? 'Unknown')),
                    // Raw precedence kept, but demoted to technical
                    // detail rather than being the primary UX.
                    Text::make('Technical: numeric precedence '.($row['precedence'] ?? 'unknown'))
                        ->color('gray'),
                ]),

            Section::make('History')
                ->description('Append-only event trail for this module across every source. Never rewritten.')
                ->collapsible()
                ->schema($this->historyComponents($directory, $admin, $firm, $row['module_code'])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function traceLine(array $line): string
    {
        $text = sprintf(
            '%s — %s',
            $line['source_label'],
            $line['configured_state'],
        );

        if ($line['present']) {
            $text .= ' ('.$line['window_state'].')';
        }

        $text .= $line['is_winner']
            ? '  ← WINNING SOURCE'
            : ($line['why_not_winner'] !== null ? '  — '.$line['why_not_winner'] : '');

        return $text;
    }

    /**
     * @return list<Text>
     */
    private function historyComponents(
        PlatformEntitlementOverrideDirectoryService $directory,
        PlatformAdmin $admin,
        Firm $firm,
        string $moduleCode,
    ): array {
        try {
            $events = $directory->moduleHistory($admin, $firm, $moduleCode);
        } catch (RuntimeException $e) {
            return [Text::make($e->getMessage())->color('danger')];
        }

        if ($events->isEmpty()) {
            // A genuine measured empty, distinct from "history is not
            // available" (mission section 24).
            return [Text::make('No entitlement events recorded for this module.')];
        }

        return $events->map(fn (array $event): Text => Text::make(sprintf(
            '%s — %s (%s): %s%s. Actor: %s.',
            $event['created_at']?->toDayDateTimeString() ?? 'Unknown time',
            ucfirst((string) $event['action']),
            $event['source'] ?? 'unknown source',
            $event['enabled'] === null ? 'state unrecorded' : ($event['enabled'] ? 'enabled' : 'disabled'),
            $event['reason'] !== null ? ' — "'.$event['reason'].'"' : '',
            $event['actor_type'] === 'System'
                ? 'System (platform admin attribution recorded in security events)'
                : (string) $event['actor_type'],
        )))->all();
    }
}
