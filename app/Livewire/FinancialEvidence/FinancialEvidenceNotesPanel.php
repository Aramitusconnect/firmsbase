<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence;

use App\Integrations\Enums\FinancialEvidenceProvenance;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Livewire\FinancialEvidence\Concerns\GatesFinancialEvidenceMatterAccess;
use App\Models\FinancialEvidenceMatterNote;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * FinancialEvidenceNotesPanel — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.8). Append-only, no
 * edit path, provenance always `AttorneyConfirmedClassification`.
 * Permission gate: `canView()` (FirmOwner/Attorney/BillingStaff) for
 * reading; `canRequest()`-tier (same three roles — a note creates no
 * monetary/access consequence) for writing.
 */
class FinancialEvidenceNotesPanel extends Component implements HasActions, HasSchemas, HasTable
{
    use GatesFinancialEvidenceMatterAccess;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(int $matterId): void
    {
        $this->gateMatterAccess($matterId);

        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser !== null) {
            app(FinancialIntegrationAccessPolicyService::class)->assertCanView($firmUser);
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaActions::make([$this->addNoteAction()]),
            EmbeddedTable::make(),
        ]);
    }

    private function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label('Add Note')
            ->schema([
                Textarea::make('body')->label('Note')->required()->rows(4),
            ])
            ->action(function (array $data): void {
                $matter = $this->matter();
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return;
                }

                app(FinancialIntegrationAccessPolicyService::class)->assertCanRequest($firmUser);

                (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceMatterNote::query()->create([
                    'firm_id' => $matter->firm_id,
                    'matter_id' => $matter->id,
                    'author_firm_user_id' => $firmUser->id,
                    'body' => $data['body'],
                    'created_at' => now(),
                ]));

                Notification::make()->title('Note added')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): \Illuminate\Support\Collection {
                $matter = $this->matter();

                return (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => FinancialEvidenceMatterNote::query()
                    ->where('matter_id', $matter->id)
                    ->with('author')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (FinancialEvidenceMatterNote $n): array => [
                        'id' => $n->id,
                        'body' => $n->body,
                        'author' => $n->author?->user?->name ?? 'Unknown',
                        'created_at' => $n->created_at?->toDayDateTimeString(),
                    ]));
            })
            ->columns([
                TextColumn::make('body')->label('Note')->wrap(),
                TextColumn::make('author')->label('Author'),
                TextColumn::make('created_at')->label('Written'),
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(FinancialEvidenceProvenance::AttorneyConfirmedClassification->label())
                    ->color(FinancialEvidenceProvenance::AttorneyConfirmedClassification->badgeColor()),
            ])
            ->emptyStateHeading('No notes yet')
            ->paginated(false);
    }

    public function render()
    {
        return view('livewire.financial-evidence.notes-panel');
    }
}
