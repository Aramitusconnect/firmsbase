<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Enums\ConflictCheckResultStatus;
use App\Models\ConflictCheckResult;
use App\Models\Matter;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\ConflictCheckService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * ResolveConflictCheckResultAction — Firm Feature Manifest §1 / this
 * mission's rule #5. Calls ConflictCheckService::resolveResult()
 * directly, which is (per direct source read of the actual method
 * body, NOT assumed) the only allowlisted source of truth for what
 * this action may submit: it throws InvalidArgumentException for
 * anything other than ConfirmedConflict/Dismissed, which is why this
 * form's Select only ever offers those two options — never
 * PossibleMatch/Clear.
 *
 * DEVIATION FROM ASSUMED BEHAVIOR (documented, not silently papered
 * over): resolveResult()'s ACTUAL method body has no "resolver must
 * differ from requester" self-clearing guard — it only enforces the
 * ConfirmedConflict/Dismissed restriction above. Unlike
 * ApproveConflictResolutionAction's integration-conflict flow (which
 * DOES have a real distinctness check in transitionStatus()), no such
 * check exists anywhere in ConflictCheckService. This action does not
 * fabricate one at the UI layer — see this mission's report for the
 * corresponding finding.
 *
 * Visible only for a result still in PossibleMatch (resolving an
 * already-resolved result again is not offered). Role ceiling:
 * ClientCrmAccessPolicyService::canResolveConflictResult()
 * (FirmOwner/Attorney only — the narrowest ceiling in this cluster,
 * matching Trust's own approve-tier convention).
 */
class ResolveConflictCheckResultAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolveConflictCheckResult';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('warning');
        $this->modalHeading('Resolve Conflict Check Result');
        $this->requiresConfirmation();

        $this->schema([
            Select::make('resolution')
                ->label('Resolution')
                ->options([
                    ConflictCheckResultStatus::ConfirmedConflict->value => 'Confirmed Conflict',
                    ConflictCheckResultStatus::Dismissed->value => 'Dismissed',
                ])
                ->required()
                ->native(false),
            Textarea::make('review_notes')->label('Notes (optional)')->rows(2),
        ]);

        $this->visible(function (ConflictCheckResult $record, RelationManager $livewire): bool {
            if ($record->status !== ConflictCheckResultStatus::PossibleMatch) {
                return false;
            }

            $matter = $livewire->getOwnerRecord();

            if (! $matter instanceof Matter) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $matter->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canResolveConflictResult($firmUser->role);
        });

        $this->action(function (array $data, ConflictCheckResult $record, RelationManager $livewire, ConflictCheckService $service): void {
            $matter = $livewire->getOwnerRecord();

            if (! $matter instanceof Matter) {
                return;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this matter.')->danger()->send();

                return;
            }

            if (! app(ClientCrmAccessPolicyService::class)->canResolveConflictResult($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Only a Firm Owner or Attorney may resolve a conflict check result.')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($matter, $record, $data, $firmUser, $service): void {
                    // TOCTOU: re-fetch scoped to THIS matter specifically —
                    // conflict_check_results has no firm_id/RLS of its own
                    // (see ConflictCheckResult's own docblock), so this
                    // whereHas(...matter_id...) constraint, not a bare
                    // ->find($id), is what actually prevents resolving a
                    // different firm's result by guessed id.
                    $fresh = ConflictCheckResult::query()
                        ->whereHas('conflictCheckRun', fn ($q) => $q->where('matter_id', $matter->id))
                        ->where('id', $record->id)
                        ->first();

                    if ($fresh === null) {
                        Notification::make()->title('This result is no longer available.')->danger()->send();

                        return;
                    }

                    if ($fresh->status !== ConflictCheckResultStatus::PossibleMatch) {
                        Notification::make()->title('This result has already been resolved.')->danger()->send();

                        return;
                    }

                    $resolution = ConflictCheckResultStatus::from((string) $data['resolution']);
                    $notes = trim((string) ($data['review_notes'] ?? '')) ?: null;

                    try {
                        $service->resolveResult($fresh, $resolution, $firmUser->user, $notes);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Could not resolve result')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Conflict check result resolved')->success()->send();
                },
            );
        });
    }
}
