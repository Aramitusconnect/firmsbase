<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Concerns;

use App\Filament\Firm\Resources\CommunicationConsentResource\Support\ConsentFormFields;
use App\Models\Client;
use App\Services\ConsentAccessPolicyService;
use App\Services\ConsentService;
use App\Services\TenantContextService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * CapturesConsent — the ONE place both CaptureConsentAction
 * (CommunicationConsentResource header action) and
 * CaptureClientConsentAction (ClientResource\
 * CommunicationConsentsRelationManager header action) turn submitted
 * form data into a call to `ConsentService::capture()`. Never calls
 * `CommunicationConsent::create()`/`update()` directly — this is a thin
 * adapter, matching RecordsManualPayment's own established shape for
 * this codebase.
 *
 * Tenant-context discipline: this Action's closure executes through
 * Filament's shared Livewire AJAX endpoint (no ambient
 * app.current_firm_id — see WrapsRecordMutationInFirmContext's own
 * docblock for the confirmed root cause). The Client is therefore
 * resolved fresh by primary key INSIDE an explicit runWithFirmContext()
 * wrap (TOCTOU discipline, matching RecordsManualPayment) BEFORE calling
 * `capture()` — `capture()` establishes its OWN separate
 * runWithFirmContext() wrap for the actual write, so this resolution
 * step is deliberately NOT nested inside that same wrap (this
 * codebase's own "decoy wrap"/double-wrap avoidance convention).
 *
 * `captured_ip` is always derived from the current request here — never
 * a form field a user could type an arbitrary value into.
 */
trait CapturesConsent
{
    protected function captureConsent(array $data): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(ConsentAccessPolicyService::class)->canCapture($firmUser->role)) {
            Notification::make()->title('Not permitted')->body('Your role may not record communication consent.')->danger()->send();

            return;
        }

        $extracted = ConsentFormFields::extract($data);

        /** @var Client|null $client */
        $client = app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): ?Client => Client::query()->where('id', $extracted['clientId'])->first(),
        );

        if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
            Notification::make()->title('Could not record consent')->body('The selected client could not be found for your firm.')->danger()->send();

            return;
        }

        $consent = app(ConsentService::class)->capture(
            firm: $firmUser->firm,
            clientId: $client->id,
            channel: $extracted['channel'],
            consentTextVersion: $extracted['consentTextVersion'],
            actor: $firmUser->user,
            capturedVia: $extracted['capturedVia'],
            capturedIp: request()?->ip(),
            expiresAt: $extracted['expiresAt'] !== null ? new \DateTimeImmutable($extracted['expiresAt']) : null,
        );

        Notification::make()
            ->title('Consent recorded')
            ->body(str($consent->channel->value)->headline().' consent granted for '.$client->display_name.'.')
            ->success()
            ->send();
    }
}
