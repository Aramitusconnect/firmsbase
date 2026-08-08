<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Actions;

use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Services\ConsentAccessPolicyService;
use App\Services\ConsentService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RevokeConsentAction — a row action, reused identically on
 * CommunicationConsentResource's own table AND ClientResource\
 * CommunicationConsentsRelationManager's table (same shared class, no
 * duplicated wiring — mirrors ResolveConflictCheckResultAction's own
 * "one Action class, several tables" shape). Only ever visible on a
 * Granted row (Firm Feature Manifest §16 / this mission's requirement
 * #3: "available on Granted rows only") and wired directly to
 * `ConsentService::revoke()` — never a bare `CommunicationConsent::
 * update()`.
 *
 * Tenant-context discipline matches CapturesConsent: the record is
 * re-fetched fresh by primary key inside its own runWithFirmContext()
 * wrap (TOCTOU discipline) before calling `revoke()`, which establishes
 * its own separate wrap for the actual write.
 */
class RevokeConsentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeConsent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Revoke communication consent');
        $this->modalDescription('This withdraws a previously granted consent. The client will no longer be considered contactable on this channel.');
        $this->modalSubmitActionLabel('Revoke Consent');

        $this->schema([
            Textarea::make('reason')
                ->label('Reason (optional)')
                ->rows(3),
        ]);

        $this->visible(function (CommunicationConsent $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if ($record->status !== ConsentStatus::Granted) {
                return false;
            }

            return app(ConsentAccessPolicyService::class)->canRevoke($firmUser->role);
        });

        $this->action(function (array $data, CommunicationConsent $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ConsentAccessPolicyService::class)->canRevoke($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not revoke communication consent.')->danger()->send();

                return;
            }

            /** @var CommunicationConsent|null $fresh */
            $fresh = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                fn (): ?CommunicationConsent => CommunicationConsent::query()->where('id', $record->id)->first(),
            );

            if ($fresh === null || (int) $fresh->firm_id !== (int) $firmUser->firm_id) {
                Notification::make()->title('Could not revoke consent')->body('This consent record could not be found for your firm.')->danger()->send();

                return;
            }

            if ($fresh->status !== ConsentStatus::Granted) {
                Notification::make()->title('Consent is not currently granted')->body('Only a Granted consent can be revoked.')->danger()->send();

                return;
            }

            try {
                app(ConsentService::class)->revoke(
                    firm: $firmUser->firm,
                    clientId: $fresh->client_id,
                    channel: $fresh->channel,
                    actor: $firmUser->user,
                    reason: filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                );
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not revoke consent')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Consent revoked')->success()->send();
        });
    }
}
