<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryCorrectionRequestResource\Pages;

use App\Filament\Actions\Platform\ApproveCorrectionRequestAction;
use App\Filament\Actions\Platform\MarkCorrectionUnderReviewAction;
use App\Filament\Actions\Platform\RejectCorrectionRequestAction;
use App\Filament\Actions\Platform\ResolveCorrectionRequestAction;
use App\Filament\Resources\DirectoryCorrectionRequestResource;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewDirectoryCorrectionRequest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT5). Adds a real CURRENT DATA vs
 * REPORTED ISSUE comparison (this mission's own spec section 7B) —
 * "requested change" isn't captured as structured data at submission
 * time (directory_correction_requests only ever stored a free-text
 * description, confirmed by this mission's own discovery pass), so
 * this is an honest best-effort: the firm's actual current field
 * values, side by side with what the reporter wrote. The Resolve
 * action (see ResolveCorrectionRequestAction) is where an admin can
 * now actually type a new value per field — this page only displays
 * the "before" side plus the free-text report.
 */
class ViewDirectoryCorrectionRequest extends ViewRecord
{
    protected static string $resource = DirectoryCorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MarkCorrectionUnderReviewAction::make(),
            ApproveCorrectionRequestAction::make(),
            RejectCorrectionRequestAction::make(),
            ResolveCorrectionRequestAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')
                ->columns(2)
                ->schema([
                    TextEntry::make('directoryFirm.display_name')->label('Listing'),
                    TextEntry::make('correction_type')->label('Category')->formatStateUsing(fn (CorrectionType $state) => $state->label())->badge(),
                    TextEntry::make('state')->badge()->formatStateUsing(fn (CorrectionState $state) => Str::headline($state->value)),
                    TextEntry::make('created_at')->label('Reported')->dateTime(),
                    TextEntry::make('description')->label('Reported Issue')->columnSpanFull(),
                ]),
            Section::make('Current Data')
                ->description('The listing\'s actual current values, for comparison against the reported issue above.')
                ->columns(2)
                ->schema([
                    TextEntry::make('current_display_name')->label('Display Name')->state(fn (DirectoryCorrectionRequest $record) => $this->currentFirm($record)?->display_name ?? '—'),
                    TextEntry::make('current_phone')->label('Phone')->state(fn (DirectoryCorrectionRequest $record) => $this->currentFirm($record)?->phone ?? '—'),
                    TextEntry::make('current_website')->label('Website')->state(fn (DirectoryCorrectionRequest $record) => $this->currentFirm($record)?->website ?? '—'),
                    TextEntry::make('current_public_email')->label('Public Email')->state(fn (DirectoryCorrectionRequest $record) => $this->currentFirm($record)?->public_email ?? '—'),
                    TextEntry::make('current_description')->label('Description')->columnSpanFull()->state(fn (DirectoryCorrectionRequest $record) => $this->currentFirm($record)?->description ?? '—'),
                ])
                ->collapsible(),
            Section::make('Reporter')
                ->columns(2)
                ->schema([
                    TextEntry::make('reporter_name')->label('Reporter Name')->placeholder('Anonymous'),
                    TextEntry::make('reporter_email')->label('Reporter Email')->placeholder('—'),
                ]),
            Section::make('Review')
                ->columns(2)
                ->schema([
                    TextEntry::make('reviewer_notes')->label('Reviewer Notes')->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('—'),
                    TextEntry::make('resolution_notes')->label('Resolution Notes')->placeholder('—'),
                    TextEntry::make('decided_at')->dateTime()->placeholder('Not yet decided'),
                ]),
            Section::make('History')
                ->schema([
                    RepeatableEntry::make('profileVersions')
                        ->hiddenLabel()
                        ->state(fn (DirectoryCorrectionRequest $record): array => DirectoryProfileVersion::query()
                            ->where('directory_firm_id', $record->directory_firm_id)
                            ->orderByDesc('created_at')
                            ->limit(10)
                            ->get()
                            ->map(fn ($v) => ['fields' => implode(', ', array_keys($v->changed_fields)), 'actor' => Str::headline($v->actor_type), 'when' => $v->created_at?->toDateTimeString()])
                            ->all())
                        ->schema([
                            TextEntry::make('fields')->label('Changed Fields'),
                            TextEntry::make('actor')->label('Actor'),
                            TextEntry::make('when')->label('When'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
        ]);
    }

    private function currentFirm(DirectoryCorrectionRequest $record): ?DirectoryFirm
    {
        return DirectoryFirm::query()->find($record->directory_firm_id);
    }
}
