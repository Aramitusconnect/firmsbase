<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\LegalHoldScope;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\LegalHoldService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlaceLegalHoldAction — LegalHoldResource's List page header action
 * (not a row action — placing a hold creates a new cross-firm row, it
 * does not act on an existing one). Routes exclusively through
 * LegalHoldService::place(), which accepts a loosely-typed object
 * $placedBy — the currently-authenticated PlatformAdmin is passed
 * directly, no actor-type gap (unlike the Operations domain's
 * User-vs-PlatformAdmin problem — see LegalHoldService's own docblock).
 *
 * TOCTOU-safe: the acting admin is re-resolved fresh from the auth
 * guard inside the closure. LegalHoldService::place()/release() carry
 * NO authorization logic of their own (confirmed directly against the
 * service), so this Action is where
 * PlatformStaffAccessPolicyService::canManageLegalHolds() is enforced —
 * both the "manage" gate and the blanket canMutate() rule, mirroring
 * every other mutating Action in this mission.
 *
 * client_id/matter_id/document_id are plain numeric internal-id inputs
 * (this admin console has no cross-firm live-search-by-name for
 * client/matter/document rows — those are tenant-owned, FORCE-RLS
 * records this panel has no standing per-firm search index over), shown
 * conditionally based on the selected scope, with explicit helper text
 * clarifying they must be the target firm's own internal record id.
 */
class PlaceLegalHoldAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'placeLegalHold';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Place Legal Hold');
        $this->icon(Heroicon::OutlinedLockClosed);
        $this->color('danger');

        $this->schema([
            Select::make('firm_id')
                ->label('Firm')
                ->searchable()
                ->required()
                ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('scope_type')
                ->label('Scope')
                ->required()
                ->native(false)
                ->options([
                    LegalHoldScope::Firm->value => 'Entire firm',
                    LegalHoldScope::Client->value => 'A specific client',
                    LegalHoldScope::Matter->value => 'A specific matter',
                    LegalHoldScope::Document->value => 'A specific document',
                ])
                ->live(),
            TextInput::make('subject_id')
                ->label('Client/Matter/Document internal id')
                ->numeric()
                ->helperText('The target firm\'s own internal record id for the selected scope. Required unless scope is "Entire firm".')
                ->hidden(fn (Get $get): bool => ($get('scope_type') ?? LegalHoldScope::Firm->value) === LegalHoldScope::Firm->value)
                ->requiredUnless('scope_type', LegalHoldScope::Firm->value),
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(3),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Place Legal Hold');
        $this->modalDescription('A Firm-scope hold blocks deletion/offboarding for the ENTIRE firm. A Client/Matter/Document-scope hold blocks only that specific subject.');
        $this->modalSubmitActionLabel('Place Hold');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, LegalHoldService $legalHoldService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageLegalHolds($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::query()->find($data['firm_id'] ?? null);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $scope = LegalHoldScope::from($data['scope_type']);
            $subjectId = $data['subject_id'] ?? null;
            $subjectId = $subjectId === null || $subjectId === '' ? null : (int) $subjectId;

            if ($scope !== LegalHoldScope::Firm && $subjectId === null) {
                Notification::make()->title('A subject id is required for this scope.')->danger()->send();

                return;
            }

            [$client, $matter, $document] = (new TenantContextService)->runWithFirmContext($firm, function () use ($scope, $subjectId, $firm): array {
                return [
                    $scope === LegalHoldScope::Client ? Client::query()->where('firm_id', $firm->id)->find($subjectId) : null,
                    $scope === LegalHoldScope::Matter ? Matter::query()->where('firm_id', $firm->id)->find($subjectId) : null,
                    $scope === LegalHoldScope::Document ? Document::query()->where('firm_id', $firm->id)->find($subjectId) : null,
                ];
            });

            if ($scope === LegalHoldScope::Client && $client === null) {
                Notification::make()->title('That client could not be found for this firm.')->danger()->send();

                return;
            }

            if ($scope === LegalHoldScope::Matter && $matter === null) {
                Notification::make()->title('That matter could not be found for this firm.')->danger()->send();

                return;
            }

            if ($scope === LegalHoldScope::Document && $document === null) {
                Notification::make()->title('That document could not be found for this firm.')->danger()->send();

                return;
            }

            $hold = $legalHoldService->place($firm, $scope, (string) $data['reason'], $actor, $client, $matter, $document);

            Notification::make()
                ->title('Legal hold placed')
                ->body("Hold #{$hold->id} is now Active for {$firm->name}.")
                ->success()
                ->send();
        });
    }
}
