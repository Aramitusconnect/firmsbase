<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Actions;

use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationConflictService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ApproveConflictResolutionAction — Checkpoint 10 (frozen-design-post-
 * security-review.md §7; agent-10h-architecture-security-review.md §6).
 * Actor B's half of the two-actor dual-approval flow — visible ONLY
 * when a conflict is AwaitingReview with a proposer already set
 * (ProposeConflictResolutionAction having already run).
 *
 * No `proposed_outcome` column exists on `integration_conflicts` (and
 * none may be added — zero new migrations this checkpoint) —
 * proposeResolution() records only the PROPOSER's identity, not the
 * specific outcome they proposed, folding it instead into
 * `resolution_note`'s free text for human legibility. Actor B therefore
 * independently selects/confirms the resolution outcome in their own
 * form below, exactly as the frozen design's own two-actor design
 * describes ("Approve Resolution... calls the existing, unmodified
 * transitionStatus(...)" with Actor B supplying $newStatus).
 *
 * The handler below: (a) re-fetches the conflict fresh (TOCTOU, frozen
 * design §10), (b) explicitly compares the current actor's FirmUser id
 * against `resolved_by_firm_user_id` and rejects with a friendly
 * message if they match (UX only, NOT the security boundary), then (c)
 * calls the EXISTING, UNMODIFIED transitionStatus(...).
 * transitionStatus()'s own inline distinctness check remains the final,
 * authoritative, un-bypassable enforcement regardless of step (b)'s
 * pre-check.
 *
 * Entitlement/role wiring (frozen design §4 item 4): checked HERE,
 * before invoking transitionStatus() — never inside that service.
 */
class ApproveConflictResolutionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveConflictResolution';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve Resolution');
        $this->icon(Heroicon::OutlinedHandThumbUp);
        $this->color('success');

        $this->schema([
            Select::make('approved_outcome')
                ->label('Confirm outcome')
                ->options(ProposeConflictResolutionAction::outcomeOptions())
                ->required()
                ->native(false),
            Textarea::make('resolution_note')
                ->label('Approval note (optional)')
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Approve Conflict Resolution');
        $this->modalDescription(function (IntegrationConflict $record): string {
            // UX-only, read-only re-fetch: this display must reflect the
            // proposal as it stands right now, not a mount()-time cached
            // value — a stale note here would defeat the whole point of
            // surfacing it. This is deliberately independent of the
            // action()'s own TOCTOU fresh-fetch (frozen design §10) —
            // that one gates authorization/status and must never be
            // shared with or weakened by this purely-cosmetic read.
            //
            // PRODUCTION BUG FIX: like the action() closure below, this
            // runs via Filament's shared `livewire/update` AJAX endpoint
            // (evaluated during mountAction()), which never establishes
            // this app's tenant context — without this wrap, the read
            // below silently found zero rows under FORCE RLS and always
            // rendered the "left no note" branch, regardless of the real
            // note. Uses `first()`, not `firstOrFail()` — a null result
            // (e.g. genuinely cross-firm) still degrades to the generic
            // "left no note" copy rather than throwing from a purely
            // cosmetic read.
            $firmUser = Auth::user()?->activeFirmUser();

            $fresh = $firmUser === null
                ? null
                : app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    fn () => IntegrationConflict::query()->where('id', $record->id)->first(),
                );

            $note = trim((string) ($fresh?->resolution_note ?? ''));

            $proposed = $note === ''
                ? 'Actor A proposed this resolution but left no note.'
                : "Actor A proposed: {$note}";

            return "{$proposed} You must be a different firm user than the one who proposed this resolution.";
        });

        $this->visible(function (IntegrationConflict $record): bool {
            if ($record->status !== ConflictStatus::AwaitingReview || $record->resolved_by_firm_user_id === null) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
                && app(IntegrationAccessPolicyService::class)->canConfigure($firmUser->role);
        });

        $this->action(function (IntegrationConflict $record, array $data, IntegrationConflictService $conflicts): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this conflict.')->danger()->send();

                return;
            }

            // PRODUCTION BUG FIX: this closure runs via Filament's shared
            // `livewire/update` AJAX endpoint, which never runs this app's
            // `EstablishFirmTenantContext` middleware (see
            // ViewFirmIntegration's docblock for the full root cause), so
            // the fresh re-fetch below previously found zero rows under
            // FORCE RLS on `integration_conflicts` even for a legitimate,
            // authorized user. IntegrationConflictService::transitionStatus()
            // also performs raw Eloquent writes/reads against that same
            // FORCE-RLS table and establishes no tenant context of its
            // own, so it must run inside this SAME wrap too — everything
            // below is otherwise byte-for-byte the prior TOCTOU/
            // authorization sequence.
            app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $data, $firmUser, $conflicts): void {
                // TOCTOU (frozen design §10.2 item 3): re-fetch fresh and
                // re-verify still AwaitingReview with a still-different
                // proposer — never trust anything rendered at page-load
                // time, which could be stale if a third actor acted on this
                // row in between.
                $fresh = IntegrationConflict::query()->where('id', $record->id)->firstOrFail();

                if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                    Notification::make()->title('You do not have access to this conflict.')->danger()->send();

                    return;
                }

                try {
                    app(IntegrationEntitlementPolicyService::class)->assertEnabled($firmUser->firm);
                    app(IntegrationAccessPolicyService::class)->assertCanConfigure($firmUser);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return;
                }

                if ($fresh->status !== ConflictStatus::AwaitingReview || $fresh->resolved_by_firm_user_id === null) {
                    Notification::make()
                        ->title('This conflict is no longer awaiting approval.')
                        ->danger()
                        ->send();

                    return;
                }

                // Step (b): UX-only friendly pre-check — NEVER the security
                // boundary. transitionStatus()'s own inline distinctness
                // check below remains the final, un-bypassable enforcement
                // regardless of this comparison.
                if ((int) $firmUser->id === (int) $fresh->resolved_by_firm_user_id) {
                    Notification::make()
                        ->title('You proposed this resolution')
                        ->body('A different approver must confirm it.')
                        ->danger()
                        ->send();

                    return;
                }

                $approvedOutcome = ConflictStatus::from((string) $data['approved_outcome']);
                $note = trim((string) ($data['resolution_note'] ?? '')) ?: null;

                try {
                    $conflicts->transitionStatus(
                        $fresh,
                        $approvedOutcome,
                        resolvedByFirmUserId: $fresh->resolved_by_firm_user_id,
                        resolutionApprovedByFirmUserId: (int) $firmUser->id,
                        resolutionNote: $note,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not approve this resolution')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Conflict resolved')->success()->send();
            });
        });
    }
}
