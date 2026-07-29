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
use Illuminate\Support\Collection;
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

        // Was `if ($firmUser !== null) { assertCanView(...) }` — a null
        // acting FirmUser silently SKIPPED the tier check. The shared
        // helper fails closed instead, and resolves the FirmUser within
        // the matter's own firm rather than taking whichever active
        // membership happened to come first.
        $this->gateFinancialTierAccess($this->matter());
    }

    public function content(Schema $schema): Schema
    {
        $this->gatedMatter();

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
                // Both gates re-run independently for the mutation, then
                // the (narrower-in-intent) request tier on top.
                [$matter, $firmUser] = $this->gatedFinancialEvidenceContext();

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
        // NOTE: the gate deliberately lives in the data-producing
        // closures below (and in every record action), not in this
        // builder body — `table()` is invoked during schema
        // construction, before any tenant context exists, and produces
        // no rows itself. Every path that actually READS or WRITES a
        // row re-runs both gates.
        return $table
            ->records(function (): Collection {
                $matter = $this->gatedMatter();

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
        $this->gatedMatter();

        return view('livewire.financial-evidence.notes-panel');
    }
}
