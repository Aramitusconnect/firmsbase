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
 * ProposeConflictResolutionAction — Checkpoint 10 (frozen-design-post-
 * security-review.md §7; agent-10h-architecture-security-review.md §6).
 * Actor A's half of the two-actor dual-approval flow required for
 * privileged/flagged conflicts (IntegrationConflictService's own
 * PRIVILEGED_RESOURCE_TYPES list, plus any row with
 * requires_manual_review = true) — transitionStatus()'s own inline
 * distinctness check makes a naive single-actor resolved-shaped
 * transition structurally impossible for those rows, so this action
 * calls the new proposeResolution() instead (§ below), which writes the
 * non-resolved-shaped AwaitingReview transition and records the
 * proposer's identity without ever touching
 * resolution_approved_by_firm_user_id.
 *
 * Visible on Detected/AwaitingReview conflicts with no existing
 * proposer (resolved_by_firm_user_id === null) — Actor B's
 * ApproveConflictResolutionAction becomes visible only once this
 * action has run.
 *
 * Entitlement/role wiring (frozen design §4 item 4): checked HERE, in
 * this caller-layer action handler, before invoking
 * IntegrationConflictService::proposeResolution() — never inside that
 * service.
 *
 * TOCTOU discipline (frozen design §10): re-fetches the conflict fresh
 * by primary key INSIDE this closure — never reuses a mount()-hydrated
 * table row — and re-runs every check unconditionally.
 */
class ProposeConflictResolutionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'proposeConflictResolution';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Propose Resolution');
        $this->icon(Heroicon::OutlinedHandRaised);
        $this->color('primary');

        $this->schema([
            Select::make('proposed_outcome')
                ->label('Proposed outcome')
                ->options(self::outcomeOptions())
                ->required()
                ->native(false),
            Textarea::make('resolution_note')
                ->label('Note (optional)')
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Propose Conflict Resolution');
        $this->modalDescription('A different, second firm user must approve this proposed outcome before the conflict is actually resolved.');

        $this->visible(function (IntegrationConflict $record): bool {
            if (! in_array($record->status, ConflictStatus::openStates(), true)) {
                return false;
            }

            if ($record->resolved_by_firm_user_id !== null) {
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
            // authorized user. IntegrationConflictService::proposeResolution()
            // also performs raw Eloquent writes/reads against that same
            // FORCE-RLS table and establishes no tenant context of its
            // own, so it must run inside this SAME wrap too — everything
            // below is otherwise byte-for-byte the prior TOCTOU/
            // authorization sequence.
            app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $data, $firmUser, $conflicts): void {
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

                if (! $fresh->isOpen() || $fresh->resolved_by_firm_user_id !== null) {
                    Notification::make()
                        ->title('This conflict already has a proposed resolution or is no longer open.')
                        ->danger()
                        ->send();

                    return;
                }

                $proposedOutcome = ConflictStatus::from((string) $data['proposed_outcome']);
                $note = trim((string) ($data['resolution_note'] ?? ''));
                $note = $note === '' ? null : "Proposed outcome: {$proposedOutcome->value}. {$note}";
                $note ??= "Proposed outcome: {$proposedOutcome->value}.";

                try {
                    $conflicts->proposeResolution($fresh, $proposedOutcome, (int) $firmUser->id, $note);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not propose a resolution')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Resolution proposed')
                    ->body('A different, second firm user must now approve this proposed outcome.')
                    ->success()
                    ->send();
            });
        });
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeOptions(): array
    {
        return [
            ConflictStatus::ResolvedLocalWins->value => 'Keep the FirmsBase (local) version',
            ConflictStatus::ResolvedRemoteWins->value => 'Keep the provider (external) version',
            ConflictStatus::ResolvedMerged->value => 'Merged',
            ConflictStatus::Ignored->value => 'Ignore this conflict',
        ];
    }
}
