<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DeadlineResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\DeadlineResource;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * EditDeadline — deliberately overrides the page-level `form()` with a
 * NARROWER schema than DeadlineResource::form() (which CreateDeadline
 * uses in full) — see DeadlinePolicy's own docblock for the reasoning:
 * DeadlineService has no update() method, and `due_at`/`deadline_type`/
 * `matter_id`/`status` are excluded here because changing them would
 * silently desynchronize the CalendarEvent DeadlineService::create()
 * already created, or bypass a lifecycle transition that belongs to
 * CompleteDeadlineAction/CancelDeadlineAction instead. Only
 * title/jurisdiction/source/reminder_offsets_days remain — none of
 * these carry any invariant a service layer would need to protect,
 * same reasoning ClientResource's EditClient documents for its own
 * narrow allowlist. Direct Eloquent update via
 * WrapsRecordMutationInFirmContext is therefore acceptable here.
 */
class EditDeadline extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = DeadlineResource::class;

    /**
     * TagsInput submits reminder_offsets_days as an array of strings;
     * normalize to ints so the stored shape always matches what
     * DeadlineService::create()/reminderDates() expect (the latter
     * type-hints `int $days` in its own array_map()).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['reminder_offsets_days']) && is_array($data['reminder_offsets_days'])) {
            $data['reminder_offsets_days'] = array_map('intval', $data['reminder_offsets_days']);
        }

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deadline')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('jurisdiction')->maxLength(255),
                    TextInput::make('source')->maxLength(255),
                    TagsInput::make('reminder_offsets_days')
                        ->label('Reminder Offsets (days before due)')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
