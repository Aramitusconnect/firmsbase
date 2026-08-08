<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Support;

use Filament\Forms\Components\TextInput;

/**
 * ClientConversionFormFields — the ONE shared form-schema piece behind
 * both "+ Add Client" (ClientResource\Pages\ListClients' AddClientAction)
 * and "Convert to Client" (FirmLeadResource\Actions\ConvertLeadToClientAction),
 * per this mission's own instruction ("reuse logic/form... where
 * sensible — a shared conversion form component is fine").
 *
 * Every field here maps 1:1 to LeadConversionService::convert()'s own
 * `$clientAttributes` array — never `status`/`converted_client_id`/
 * `converted_at`/`portal_status`/`communication_preferences_id`/
 * `created_by` (firm_id and created_by are set by convert() itself,
 * never the caller — see that method's own docblock).
 */
final class ClientConversionFormFields
{
    /**
     * @return array<int, TextInput>
     */
    public static function schema(): array
    {
        return [
            TextInput::make('display_name')
                ->label('Client Name')
                ->required()
                ->maxLength(255),
            TextInput::make('legal_name')
                ->label('Legal Name')
                ->maxLength(255)
                ->helperText('Leave blank if the same as Client Name.'),
            TextInput::make('email')
                ->label('Client Email')
                ->email()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Client Phone')
                ->maxLength(255),
            TextInput::make('preferred_language')
                ->label('Preferred Language')
                ->maxLength(255)
                ->default('en'),
            TextInput::make('preferred_timezone')
                ->label('Preferred Timezone')
                ->maxLength(255)
                ->default('America/New_York'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function extractClientAttributes(array $data): array
    {
        return [
            'display_name' => $data['display_name'],
            'legal_name' => $data['legal_name'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'preferred_language' => $data['preferred_language'] ?: 'en',
            'preferred_timezone' => $data['preferred_timezone'] ?: null,
        ];
    }
}
