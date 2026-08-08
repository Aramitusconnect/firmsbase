<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Actions;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Support\ClientConversionFormFields;
use App\Models\FirmLead;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\LeadConversionService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ConvertLeadToClientAction — the ONLY row action on FirmLeadResource
 * that may transition a lead's status to Converted, and it does so
 * exclusively by calling LeadConversionService::convert() (this
 * mission's rule #4). Shares its Client Profile form fields with
 * ClientResource\Actions\AddClientAction via
 * ClientConversionFormFields (this mission's own "a shared conversion
 * form component is fine" allowance) — pre-filled from the lead's own
 * name/email/phone so the reviewer isn't re-typing data already
 * captured at intake.
 *
 * TOCTOU + tenant-context discipline matches every other Action in
 * this panel (see AddClientAction's own docblock for the full,
 * already-confirmed root cause this wrap fixes): re-fetches the lead
 * fresh by primary key INSIDE runWithFirmContext(), re-checks
 * isConverted() there (never trusts the row rendered at page-load
 * time — a second browser tab could have converted it in between),
 * and re-checks the role ceiling there too.
 */
class ConvertLeadToClientAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'convertToClient';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Convert to Client');
        $this->icon(Heroicon::OutlinedArrowRightCircle);
        $this->color('success');
        $this->modalHeading('Convert Lead to Client');
        $this->modalDescription('This calls the same lead-conversion service used everywhere in this system — it never sets "Converted" directly on the lead.');
        $this->modalSubmitActionLabel('Convert');
        $this->modalWidth('xl');

        $this->schema(ClientConversionFormFields::schema());

        $this->fillForm(fn (FirmLead $record): array => [
            'display_name' => $record->name,
            'legal_name' => null,
            'email' => $record->email,
            'phone' => $record->phone,
            'preferred_language' => 'en',
            'preferred_timezone' => null,
        ]);

        $this->visible(function (FirmLead $record): bool {
            if ($record->isConverted()) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        });

        $this->action(function (array $data, FirmLead $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this lead.')->danger()->send();

                return;
            }

            if (! app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not convert leads to clients.')->danger()->send();

                return;
            }

            $client = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $data, $firmUser) {
                    $fresh = FirmLead::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        return null;
                    }

                    if ($fresh->isConverted()) {
                        return false;
                    }

                    try {
                        return app(LeadConversionService::class)->convert(
                            $fresh,
                            ClientConversionFormFields::extractClientAttributes($data),
                            $firmUser->user,
                        );
                    } catch (RuntimeException $e) {
                        report($e);

                        return null;
                    }
                },
            );

            if ($client === false) {
                Notification::make()->title('This lead was already converted')->danger()->send();

                return;
            }

            if ($client === null) {
                Notification::make()->title('Could not convert lead')->danger()->send();

                return;
            }

            Notification::make()->title('Lead converted to Client')->success()->send();

            $this->redirect(ClientResource::getUrl('view', ['record' => $client]));
        });
    }
}
