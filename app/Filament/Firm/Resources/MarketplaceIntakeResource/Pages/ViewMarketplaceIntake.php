<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages;

use App\Enums\MarketplaceIntakeStatus;
use App\Filament\Firm\Resources\MarketplaceIntakeResource;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\AcceptIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\ClearIntakeConflictReviewAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\ConvertIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\DeclineIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\GenerateAiSummaryAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\MarkUnderReviewAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\RunIntakeConflictCheckAction;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Marketplace\Services\MarketplaceIntakeConflictCheckService;
use App\Marketplace\Services\MarketplaceIntakeDocumentService;
use App\Models\Document;
use App\Models\Firm;
use App\Services\IntakeTemplateService;
use App\Services\TenantContextService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * ViewMarketplaceIntake — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. Read-only Infolist only — no form()/EditRecord
 * page exists (mirrors ViewPaymentRequest's own "no direct field
 * editing, only named actions" shape). "Documents" and "Possible
 * Conflict Matches" are read via the SAME service-layer safety
 * boundaries checkpoints 7/8 already built
 * (MarketplaceIntakeDocumentService::usableDocumentsForFirmReview(),
 * MarketplaceIntakeConflictCheckService::possibleMatches()) — never a
 * raw $record->documents() relation, which would bypass the
 * not-yet-scanned-clean filter.
 */
class ViewMarketplaceIntake extends ViewRecord
{
    protected static string $resource = MarketplaceIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MarkUnderReviewAction::make(),
            RunIntakeConflictCheckAction::make(),
            ClearIntakeConflictReviewAction::make(),
            AcceptIntakeAction::make(),
            DeclineIntakeAction::make(),
            ConvertIntakeAction::make(),
            GenerateAiSummaryAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prospect')
                ->columns(2)
                ->schema([
                    TextEntry::make('prospect_name')->label('Name')->placeholder('—'),
                    TextEntry::make('prospect_email')->label('Email')->placeholder('—'),
                    TextEntry::make('prospect_phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('practiceArea.name')->label('Practice Area')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? str($state->value)->replace('_', ' ')->headline()->toString() : (string) $state),
                    TextEntry::make('ai_assisted')->label('AI-Assisted Intake')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    TextEntry::make('submitted_at')->dateTime()->placeholder('Not yet submitted'),
                    TextEntry::make('under_review_at')->label('Under Review Since')->dateTime()->placeholder('—'),
                ]),

            Section::make('Intake Answers')
                ->schema(fn (MarketplaceIntake $record): array => $this->answerEntries($record)),

            Section::make('AI Summary')
                ->description('Disposable review aid — not legal advice, never authoritative.')
                ->schema([
                    TextEntry::make('ai_summary')->hiddenLabel()->placeholder('Not yet generated.')->columnSpanFull(),
                    TextEntry::make('ai_summary_generated_at')->label('Generated')->dateTime()->placeholder('—'),
                ]),

            Section::make('Documents')
                ->schema([
                    RepeatableEntry::make('documents')
                        ->hiddenLabel()
                        ->state(fn (MarketplaceIntake $record): array => $this->documentEntries($record))
                        ->schema([
                            TextEntry::make('original_filename')->label('File'),
                            TextEntry::make('status')->label('Status'),
                            TextEntry::make('created_at')->label('Uploaded'),
                        ])
                        ->columns(3),
                ]),

            Section::make('Possible Conflict Matches')
                ->description('Every match is flagged for human review only — no legal determination has been made.')
                ->schema([
                    RepeatableEntry::make('conflictMatches')
                        ->hiddenLabel()
                        ->state(fn (MarketplaceIntake $record): array => $this->conflictMatchEntries($record))
                        ->schema([
                            TextEntry::make('type')->label('Type'),
                            TextEntry::make('value')->label('Matched Value'),
                        ])
                        ->columns(2),
                ]),

            Section::make('Timeline')
                ->schema([
                    RepeatableEntry::make('events')
                        ->hiddenLabel()
                        ->state(fn (MarketplaceIntake $record): array => $this->eventEntries($record))
                        ->schema([
                            TextEntry::make('event_type')->label('Event'),
                            TextEntry::make('created_at')->label('When'),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * @return array<int, TextEntry>
     */
    private function answerEntries(MarketplaceIntake $record): array
    {
        $responses = $record->structured_data ?? [];

        if ($responses === [] || $record->intakeTemplate === null) {
            return [TextEntry::make('no_answers')->hiddenLabel()->state('No structured answers were captured.')];
        }

        $labelsByCode = app(IntakeTemplateService::class)
            ->questionsFor($record->intakeTemplate)
            ->keyBy('question_code')
            ->map(fn ($question) => $question->label);

        $entries = [];

        foreach ($responses as $code => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $stringValue = is_scalar($value) ? (string) $value : json_encode($value);

            $entries[] = TextEntry::make("structured_data.{$code}")
                ->label($labelsByCode[$code] ?? $code)
                ->state($stringValue);
        }

        return $entries === [] ? [TextEntry::make('no_answers')->hiddenLabel()->state('No structured answers were captured.')] : $entries;
    }

    /**
     * @return array<int, array{original_filename: string, status: string, created_at: string}>
     */
    private function documentEntries(MarketplaceIntake $record): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $firmUser): array {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                return app(MarketplaceIntakeDocumentService::class)
                    ->usableDocumentsForFirmReview($firm, $fresh)
                    ->map(fn (Document $document): array => [
                        'original_filename' => $document->original_filename,
                        'status' => 'Clean',
                        'created_at' => $document->created_at?->toDateTimeString() ?? '—',
                    ])
                    ->all();
            },
        );
    }

    /**
     * Only evaluated while the intake is actually in a state where a
     * conflict signal is relevant — never re-runs a live search
     * against every accepted/converted/declined intake on every page
     * view.
     *
     * @return array<int, array{type: string, value: string}>
     */
    private function conflictMatchEntries(MarketplaceIntake $record): array
    {
        if (! in_array($record->status, [MarketplaceIntakeStatus::UnderReview, MarketplaceIntakeStatus::ConflictReviewRequired], true)) {
            return [];
        }

        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $firmUser): array {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                return app(MarketplaceIntakeConflictCheckService::class)
                    ->possibleMatches($firm, $fresh)
                    ->map(fn (array $match): array => [
                        'type' => str($match['type'])->headline()->toString(),
                        'value' => $match['value'],
                    ])
                    ->all();
            },
        );
    }

    /**
     * @return array<int, array{event_type: string, created_at: string}>
     */
    private function eventEntries(MarketplaceIntake $record): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn () => MarketplaceIntakeEvent::query()
                ->where('marketplace_intake_id', $record->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (MarketplaceIntakeEvent $event): array => [
                    'event_type' => str($event->event_type->value)->headline()->toString(),
                    'created_at' => $event->created_at?->toDateTimeString() ?? '—',
                ])
                ->all(),
        );
    }
}
