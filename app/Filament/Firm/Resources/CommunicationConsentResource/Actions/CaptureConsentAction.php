<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Actions;

use App\Filament\Firm\Resources\CommunicationConsentResource\Concerns\CapturesConsent;
use App\Filament\Firm\Resources\CommunicationConsentResource\Support\ConsentFormFields;
use App\Services\ConsentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CaptureConsentAction — the "Record Consent" header action on
 * CommunicationConsentResource's list page. No preselected client — the
 * acting user picks any of the firm's own clients. Wired directly to
 * `ConsentService::capture()` via CapturesConsent; never a bare
 * `CommunicationConsent::create()`.
 */
class CaptureConsentAction extends Action
{
    use CapturesConsent;

    public static function getDefaultName(): ?string
    {
        return 'captureConsent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Record Consent');
        $this->modalHeading('Record communication consent');
        $this->modalDescription('Records that a client granted consent to be contacted on a given channel. This always results in a Granted consent record — to withdraw an existing consent, use Revoke on that row instead.');
        $this->modalSubmitActionLabel('Record Consent');
        $this->modalWidth('xl');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('primary');

        $this->schema(ConsentFormFields::schema());

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(ConsentAccessPolicyService::class)->canCapture($firmUser->role);
        });

        $this->action(function (array $data): void {
            $this->captureConsent($data);
        });
    }
}
