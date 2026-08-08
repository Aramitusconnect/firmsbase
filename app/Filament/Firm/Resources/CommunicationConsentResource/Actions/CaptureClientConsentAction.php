<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Actions;

use App\Filament\Firm\Resources\CommunicationConsentResource\Concerns\CapturesConsent;
use App\Filament\Firm\Resources\CommunicationConsentResource\Support\ConsentFormFields;
use App\Models\Client;
use App\Services\ConsentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CaptureClientConsentAction — the same "Record Consent" flow as
 * CaptureConsentAction, reachable as a header action on
 * ClientResource\CommunicationConsentsRelationManager (implicit client
 * context, per the manifest's own "implicit when on the relation
 * manager" instruction). The client field is pre-filled AND locked to
 * the owning Client (`lockClient: true` — see ConsentFormFields::
 * schema()) so this can never be used to record consent against a
 * different client than the tab it was opened from. Shares 100% of its
 * submission logic with CaptureConsentAction via CapturesConsent — no
 * duplicated call to ConsentService::capture().
 *
 * Owner record access matches every other header Action hosted on a
 * RelationManager in this panel (see RunConflictCheckAction/
 * TriggerManualSyncAction's own docblocks): the closure takes
 * `RelationManager $livewire` and calls `$livewire->getOwnerRecord()`
 * rather than a directly-injected `Client $ownerRecord` parameter,
 * which Filament's action-closure resolution does not support.
 */
class CaptureClientConsentAction extends Action
{
    use CapturesConsent;

    public static function getDefaultName(): ?string
    {
        return 'captureClientConsent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Record Consent');
        $this->modalHeading('Record communication consent');
        $this->modalDescription('Records that this client granted consent to be contacted on a given channel. This always results in a Granted consent record — to withdraw an existing consent, use Revoke on that row instead.');
        $this->modalSubmitActionLabel('Record Consent');
        $this->modalWidth('xl');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('primary');

        $this->schema(ConsentFormFields::schema(lockClient: true));

        $this->fillForm(function (RelationManager $livewire): array {
            $client = $livewire->getOwnerRecord();

            return [
                'client_id' => $client instanceof Client ? $client->id : null,
            ];
        });

        $this->visible(function (RelationManager $livewire): bool {
            $client = $livewire->getOwnerRecord();

            if (! $client instanceof Client) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $client->firm_id) {
                return false;
            }

            return app(ConsentAccessPolicyService::class)->canCapture($firmUser->role);
        });

        $this->action(function (array $data): void {
            $this->captureConsent($data);
        });
    }
}
