<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeAreaResource\Pages;

use App\Filament\Actions\Platform\ActivatePracticeAreaAction;
use App\Filament\Actions\Platform\DeactivatePracticeAreaAction;
use App\Filament\Actions\Platform\EditPracticeAreaAction;
use App\Filament\Actions\Platform\ProposePracticeAreaMergeAction;
use App\Filament\Resources\PracticeAreaResource;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
use App\Services\Configuration\PracticeAreaDependencyAnalysisService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewPracticeArea — Edit/Activate/Deactivate live here as header
 * actions, mirroring ViewPlan's convention. The Matter Types relation
 * manager (registered on the parent Resource) renders below the
 * infolist as its own tab — "Practice Area → Matter Types".
 *
 * Configuration Control Plane additions — the four questions an
 * operator actually needs answered before touching canonical taxonomy:
 *
 *   WHAT IS THIS? Full canonical identity, including the public slug
 *   and marketplace visibility, which are independent of is_active.
 *
 *   WHAT DEPENDS ON IT? Exact counts for globally-readable references,
 *   and tenant-owned references named but explicitly NOT counted —
 *   those tables are FORCE-RLS protected, so a platform session's
 *   count would silently be 0 rather than an error (mission sections
 *   24/77). Counting them needs the on-demand per-firm scan.
 *
 *   DOES ANYTHING COLLIDE WITH IT? Suspected duplicates with the
 *   evidence for each match, so an operator sees WHY, not just THAT.
 *
 *   WHAT IS NOT AVAILABLE HERE? Aliases are stored but resolve
 *   nowhere, and hierarchy does not exist at all. Both are stated on
 *   the page rather than left to be inferred from an absent panel
 *   (mission sections 30/32/100).
 */
class ViewPracticeArea extends ViewRecord
{
    protected static string $resource = PracticeAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditPracticeAreaAction::make(),
            ActivatePracticeAreaAction::make(),
            DeactivatePracticeAreaAction::make(),
            ProposePracticeAreaMergeAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Canonical identity')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('code')
                        ->label('Canonical code')
                        ->helperText('The stable identifier other records resolve this taxonomy by.'),
                    TextEntry::make('slug')
                        ->label('Public slug')
                        ->placeholder('Not set'),
                    TextEntry::make('id')->label('Database ID'),
                    TextEntry::make('is_active')
                        ->label('Status')
                        // Textual, not colour-only (mission section 86).
                        ->state(fn (PracticeArea $record): string => $record->is_active ? 'Active' : 'Inactive')
                        ->badge()
                        ->color(fn (PracticeArea $record): string => $record->is_active ? 'success' : 'gray'),
                    TextEntry::make('is_marketplace_visible')
                        ->label('Marketplace visibility')
                        ->state(fn (PracticeArea $record): string => $record->is_marketplace_visible ? 'Visible' : 'Not visible')
                        ->badge()
                        ->helperText('Independent of Active — a practice area can stay active for internal use while never appearing in public marketplace search.'),
                    TextEntry::make('description')->placeholder('No description')->columnSpanFull(),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                ]),

            Section::make('Dependencies')
                ->description('What references this practice area. Deactivation and any future merge must preserve every one of these.')
                ->schema([
                    TextEntry::make('global_dependencies')
                        ->label('Counted exactly')
                        ->state(fn (PracticeArea $record): string => collect(
                            app(PracticeAreaDependencyAnalysisService::class)->globalDependencies($record)
                        )->map(fn (array $row): string => $row['label'].': '.$row['count'])->implode(' • ')),
                    TextEntry::make('tenant_dependencies')
                        ->label('Not counted here')
                        ->state(fn (): string => collect(
                            app(PracticeAreaDependencyAnalysisService::class)->tenantDependenciesUnscanned()
                        )->map(fn (array $row): string => $row['label'])->implode(', '))
                        ->helperText('Tenant-owned and protected by row-level security. A platform session sees none of these rows, so a count here would read 0 whether or not references exist. Use Propose merge to run the per-firm impact scan.'),
                ]),

            Section::make('Duplicate analysis')
                ->schema([
                    TextEntry::make('duplicate_candidates')
                        ->label('Suspected duplicates')
                        ->state(fn (PracticeArea $record): string => self::duplicateSummary($record))
                        ->helperText('Detection compares normalized name, code, slug and stored aliases. A match means the identifiers collide — never that the two are semantically the same practice area.'),
                ]),

            Section::make('Aliases and hierarchy')
                ->columns(2)
                ->schema([
                    TextEntry::make('stored_aliases')
                        ->label('Stored aliases')
                        ->state(function (PracticeArea $record): string {
                            $aliases = app(PracticeAreaCanonicalizationService::class)->aliasesOf($record);

                            return $aliases === [] ? 'None stored' : implode(', ', $aliases);
                        })
                        ->helperText('Stored in practice_areas.synonyms. ALIAS RESOLUTION IS NOT IMPLEMENTED — no code in this codebase resolves an alias to this practice area. These values are governed for data quality only.'),
                    TextEntry::make('hierarchy')
                        ->label('Parent / hierarchy')
                        ->state('Not implemented')
                        ->badge()
                        ->color('gray')
                        ->helperText('practice_areas has no parent or category column — this taxonomy is flat.'),
                ]),
        ]);
    }

    private static function duplicateSummary(PracticeArea $record): string
    {
        $candidates = app(PracticeAreaCanonicalizationService::class)->duplicateCandidatesFor(
            name: $record->name,
            code: $record->code,
            slug: $record->slug,
            aliases: app(PracticeAreaCanonicalizationService::class)->aliasesOf($record),
            excludingId: $record->getKey(),
        );

        if ($candidates->isEmpty()) {
            return 'None detected — no other practice area normalizes onto this one.';
        }

        return $candidates
            ->map(fn ($candidate): string => $candidate->summaryLine().' — matched because: '.implode('; ', $candidate->matchReasons))
            ->implode(' | ');
    }
}
