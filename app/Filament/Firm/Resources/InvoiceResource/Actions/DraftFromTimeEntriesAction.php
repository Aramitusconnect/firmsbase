<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Enums\TimeEntryStatus;
use App\Filament\Firm\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DraftFromTimeEntriesAction — the "+ Draft from Time Entries" header
 * action on ListInvoices, wired directly to
 * InvoiceDraftingService::draftFromTimeEntries() — never a bare
 * `Invoice::create()`. Only already-approved, billable, not-yet-
 * invoiced time entries belonging to the selected client are offered
 * (`TimeEntry::isEligibleForInvoicing()` plus `whereDoesntHave('invoiceLine')`
 * — an entry with an existing invoice_lines row was already invoiced
 * and would otherwise double-bill it); draftFromTimeEntries() itself
 * re-validates every one of these guards server-side regardless (see
 * that service's own docblock), so this is a UX narrowing only, not
 * the real boundary.
 *
 * Tenant-context discipline: this Action's schema/closures execute
 * through Filament's shared `livewire/update` AJAX endpoint (no
 * ambient `app.current_firm_id` — see WrapsRecordMutationInFirmContext's
 * own docblock). Every Select option list AND the final resolution of
 * Client/Matter/TimeEntry rows are therefore wrapped in an explicit
 * `runWithFirmContext()` call (mirrors AddClientAction's own
 * `lead_source_id` Select precedent), and every row is re-verified to
 * belong to the acting firm/client before being passed to the service
 * (TOCTOU discipline, matching RecordsManualPayment).
 */
class DraftFromTimeEntriesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'draftFromTimeEntries';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Draft from Time Entries');
        $this->modalHeading('Draft Invoice from Time Entries');
        $this->modalDescription('Creates a new draft invoice with one line per selected time entry. Only approved, billable, not-yet-invoiced entries for the selected client are shown.');
        $this->modalSubmitActionLabel('Draft Invoice');
        $this->modalWidth('xl');
        $this->icon(Heroicon::OutlinedDocumentPlus);
        $this->color('primary');

        $this->schema([
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()))
                ->searchable()
                ->required()
                ->live(),

            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (Get $get): array => filled($get('client_id'))
                    ? self::firmScoped(fn () => Matter::query()
                        ->where('client_id', $get('client_id'))
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                        ->all())
                    : [])
                ->searchable()
                ->nullable()
                ->live()
                ->helperText('Only matters belonging to the selected client are shown.'),

            Select::make('time_entry_ids')
                ->label('Time Entries')
                ->options(fn (Get $get): array => filled($get('client_id'))
                    ? self::firmScoped(fn () => TimeEntry::query()
                        ->where('client_id', $get('client_id'))
                        ->where('status', TimeEntryStatus::Approved)
                        ->where('is_billable', true)
                        ->whereDoesntHave('invoiceLine')
                        ->get()
                        ->mapWithKeys(fn (TimeEntry $entry): array => [
                            $entry->id => sprintf('%s — %dh %02dm', $entry->description ?? 'Time entry', intdiv($entry->seconds, 3600), intdiv($entry->seconds % 3600, 60)),
                        ])
                        ->all())
                    : [])
                ->multiple()
                ->searchable()
                ->required()
                ->helperText('Only approved, billable, not-yet-invoiced entries for the selected client are shown.'),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not draft invoices.')->danger()->send();

                return;
            }

            $invoice = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser) {
                    $client = Client::query()->where('id', $data['client_id'])->first();

                    if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
                        return null;
                    }

                    $matter = filled($data['matter_id'] ?? null)
                        ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $client->id)->first()
                        : null;

                    $timeEntries = TimeEntry::query()
                        ->whereIn('id', $data['time_entry_ids'] ?? [])
                        ->where('client_id', $client->id)
                        ->get()
                        ->all();

                    if (empty($timeEntries)) {
                        return null;
                    }

                    try {
                        return app(InvoiceDraftingService::class)->draftFromTimeEntries(
                            firm: $firmUser->firm,
                            client: $client,
                            timeEntries: $timeEntries,
                            matter: $matter,
                            createdBy: $firmUser->user,
                        );
                    } catch (\RuntimeException|\InvalidArgumentException $e) {
                        report($e);

                        return null;
                    }
                },
            );

            if ($invoice === null) {
                Notification::make()->title('Could not draft invoice')->body('The selected client/time entries could not be found or are no longer eligible.')->danger()->send();

                return;
            }

            Notification::make()->title('Invoice drafted')->success()->send();

            $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function firmScoped(callable $callback)
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, $callback);
    }
}
