<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Models\Matter;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\ConflictCheckService;
use App\Services\MatterAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * RunConflictCheckAction — Firm Feature Manifest §1 / this mission's
 * rule #5. Calls ConflictCheckService::run() directly; makes NO legal
 * determination itself (the service's own contract: every match
 * defaults to `possible_match`) — the modal description says so
 * explicitly so the UI never implies more than the backend actually
 * does.
 *
 * Gated on BOTH MatterAccessPolicyService::canAccessMatter() (the same
 * real per-record boundary every other Matter tab already uses) AND
 * ClientCrmAccessPolicyService::canRunConflictCheck() (the CRM-cluster
 * role ceiling) — a user must pass both, not just one.
 *
 * Tenant-context wrap matches every other Action in this panel (see
 * AddClientAction's docblock for the confirmed root cause) — re-fetches
 * the owning Matter fresh inside runWithFirmContext() before calling
 * the service, which itself also wraps internally (safe/re-entrant).
 */
class RunConflictCheckAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'runConflictCheck';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Run Conflict Check');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('primary');
        $this->modalHeading('Run Conflict Check');
        $this->modalDescription('Searches clients, contacts, parties, and other matters for name/email/phone matches. This makes no legal judgment — every match is flagged "possible match" for human review.');
        $this->modalSubmitActionLabel('Run Check');

        $this->schema([
            Textarea::make('search_terms')
                ->label('Names / emails / phones to search (one per line)')
                ->required()
                ->rows(3),
            Textarea::make('free_text_names')
                ->label('Additional opposing-party names with no record yet (optional, one per line)')
                ->rows(2),
        ]);

        $this->visible(function (RelationManager $livewire): bool {
            $matter = $livewire->getOwnerRecord();

            if (! $matter instanceof Matter) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $matter->firm_id) {
                return false;
            }

            if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $matter)) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canRunConflictCheck($firmUser->role);
        });

        $this->action(function (array $data, RelationManager $livewire, ConflictCheckService $service): void {
            $matter = $livewire->getOwnerRecord();

            if (! $matter instanceof Matter) {
                return;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this matter.')->danger()->send();

                return;
            }

            if (! app(ClientCrmAccessPolicyService::class)->canRunConflictCheck($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not run conflict checks.')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($matter, $data, $firmUser, $service): void {
                    $fresh = Matter::query()->where('id', $matter->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this matter.')->danger()->send();

                        return;
                    }

                    if (! app(MatterAccessPolicyService::class)->canAccessMatter($firmUser->user, $fresh)) {
                        Notification::make()->title('Not permitted')->danger()->send();

                        return;
                    }

                    $terms = self::linesToArray((string) ($data['search_terms'] ?? ''));
                    $freeTextNames = self::linesToArray((string) ($data['free_text_names'] ?? ''));

                    $summary = $service->run($fresh, $terms, $freeTextNames, $firmUser->user);

                    Notification::make()
                        ->title('Conflict check completed')
                        ->body("{$summary->resultCount} result(s) found — all flagged for review, no legal determination made.")
                        ->success()
                        ->send();
                },
            );
        });
    }

    /**
     * @return array<int, string>
     */
    private static function linesToArray(string $value): array
    {
        return Str::of($value)
            ->explode("\n")
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->values()
            ->all();
    }
}
