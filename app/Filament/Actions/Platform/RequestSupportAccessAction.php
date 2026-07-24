<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * RequestSupportAccessAction — Checkpoint 11 (frozen-design-post-
 * security-review.md §7, §8, §12). Header action on
 * App\Filament\Pages\PlatformFirmIntegrationsPage. The first real caller
 * of App\Services\SupportAccessRequestService, exclusively through
 * PlatformFirmIntegrationBoundedAccessService::requestSupportAccess()
 * (gap closure #1: request() + logNotification() as two explicit
 * sequential calls — never invoked directly here).
 *
 * Reads the acting PlatformAdmin and target Firm fresh from the current
 * request/page state inside the action() closure only — never from a
 * pre-hydrated Model-typed property (frozen design §6).
 */
class RequestSupportAccessAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requestSupportAccess';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Support Access');
        $this->icon(Heroicon::OutlinedKey);
        $this->color('gray');

        $this->schema([
            Select::make('access_type')
                ->label('Access type')
                ->options([
                    SupportAccessType::Standard->value => 'Standard (requires firm approval)',
                    SupportAccessType::Emergency->value => 'Emergency (requires platform high-risk approval)',
                ])
                ->default(SupportAccessType::Standard->value)
                ->required()
                ->native(false)
                ->live(),
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(3),
            TextInput::make('requested_duration_minutes')
                ->label('Requested duration (minutes)')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(1440)
                ->default(60),
            Textarea::make('emergency_justification')
                ->label('Emergency justification')
                ->rows(2)
                ->visible(fn (Get $get): bool => $get('access_type') === SupportAccessType::Emergency->value)
                ->required(fn (Get $get): bool => $get('access_type') === SupportAccessType::Emergency->value),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Request Support Access');

        $this->action(function (array $data, $livewire, PlatformFirmIntegrationBoundedAccessService $boundedAccess): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $livewire->firmUuid);

            $accessType = SupportAccessType::from((string) $data['access_type']);
            $emergencyJustification = trim((string) ($data['emergency_justification'] ?? ''));

            try {
                $request = $boundedAccess->requestSupportAccess(
                    $admin,
                    $firm,
                    $accessType,
                    (string) $data['reason'],
                    (int) $data['requested_duration_minutes'],
                    $emergencyJustification === '' ? null : $emergencyJustification,
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not request support access')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Support access requested')
                ->body("Request {$request->uuid} has been recorded. Enter the session once it is approved (or, for emergency access, once the platform high-risk approval is granted).")
                ->success()
                ->send();
        });
    }
}
