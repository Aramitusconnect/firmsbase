<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Support;

use App\Enums\ConsentChannel;
use App\Models\Client;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * ConsentFormFields — shared "Record Consent" form schema reused by
 * both CaptureConsentAction (CommunicationConsentResource's standalone
 * header action, no preselected client) and CaptureClientConsentAction
 * (ClientResource\CommunicationConsentsRelationManager's header action,
 * client locked to the owning Client). Mirrors RecordPaymentFormFields'
 * own "a shared record-action form component is fine" precedent.
 *
 * This form intentionally has NO "status" field. `ConsentService::
 * capture()`'s real signature (read in full before writing this file)
 * never accepts a status — every capture()/recapture() call
 * unconditionally writes `ConsentStatus::Granted`; that IS what
 * "capture" means in this domain (a granted-consent record, versioned
 * and timestamped). Offering a "status" selector here would render an
 * option this service can never honor, exactly the mistake
 * RecordPaymentFormFields' own docblock documents avoiding for
 * `classification`. The only other real transition, revoking a
 * previously granted consent, is a fully separate write path
 * (`ConsentService::revoke()`) with its own action/form
 * (RevokeConsentAction) — never bolted onto this one as a status
 * option.
 *
 * `captured_ip` is likewise not a form field — both Capture actions
 * derive it server-side from the current request
 * (`request()->ip()`) inside their own trait (CapturesConsent), the
 * same "the acting context supplies this, the user does not type it"
 * treatment RecordPaymentFormFields gives `idempotency_key`.
 */
class ConsentFormFields
{
    /**
     * @return array<int, Component>
     */
    public static function schema(bool $lockClient = false): array
    {
        return [
            Section::make('Communication Consent')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->disabled($lockClient)
                        ->dehydrated(),

                    Select::make('channel')
                        ->label('Channel')
                        ->options(collect(ConsentChannel::cases())->mapWithKeys(
                            fn (ConsentChannel $channel): array => [$channel->value => str($channel->value)->headline()]
                        )->all())
                        ->required()
                        ->native(false),

                    TextInput::make('consent_text_version')
                        ->label('Consent Text Version')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The exact version of the consent language the client agreed to (e.g. "v1", "2026-08-privacy-notice").'),

                    TextInput::make('captured_via')
                        ->label('Captured Via')
                        ->maxLength(255)
                        ->helperText('E.g. web_form, phone_call, in_person, portal.'),

                    DateTimePicker::make('expires_at')
                        ->label('Expires At')
                        ->native(false)
                        ->helperText('Leave blank if this consent does not expire.')
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array{clientId: int, channel: ConsentChannel, consentTextVersion: string, capturedVia: ?string, expiresAt: ?string}
     */
    public static function extract(array $data): array
    {
        return [
            'clientId' => (int) $data['client_id'],
            'channel' => ConsentChannel::from($data['channel']),
            'consentTextVersion' => (string) $data['consent_text_version'],
            'capturedVia' => filled($data['captured_via'] ?? null) ? (string) $data['captured_via'] : null,
            'expiresAt' => filled($data['expires_at'] ?? null) ? (string) $data['expires_at'] : null,
        ];
    }
}
