<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Pages;

use App\Filament\Firm\Resources\ExpenseResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense')
                ->columns(2)
                ->schema([
                    TextEntry::make('vendor_name')->label('Vendor'),
                    TextEntry::make('amount_cents')
                        ->label('Amount')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('category.name')->label('Category')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('expense_date')->label('Expense Date')->date(),
                    TextEntry::make('reimbursable')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('createdBy.user.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
