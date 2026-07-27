<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\NotificationTemplateService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * CreateFirmOverrideNotificationTemplateAction —
 * NotificationTemplateResource's header action. Calls
 * NotificationTemplateService::createFirmOverride(..., $actor) — the
 * actor/audit plumbing this phase added to that method. Content/
 * metadata only — see CreateGlobalDefaultNotificationTemplateAction's
 * own docblock for the "no live-send capability" disclosure, which
 * applies identically here.
 */
class CreateFirmOverrideNotificationTemplateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createFirmOverrideNotificationTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create Firm Override');
        $this->icon(Heroicon::OutlinedBuildingOffice);
        $this->color('warning');

        $this->schema([
            Select::make('firm_uuid')
                ->label('Firm')
                ->searchable()
                ->required()
                ->native(false)
                ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
            TextInput::make('key')->required(),
            Select::make('channel')
                ->required()
                ->native(false)
                ->default(ConsentChannel::Email->value)
                ->options(collect(ConsentChannel::cases())
                    ->mapWithKeys(fn (ConsentChannel $channel): array => [$channel->value => Str::headline($channel->value)])
                    ->all()),
            TextInput::make('language')->default('en')->required(),
            TextInput::make('subject')->label('Subject (optional)'),
            Textarea::make('body')->required()->rows(5),
            TextInput::make('from_email')->label('From email (optional)')->email(),
            TextInput::make('from_domain')->label('From domain (optional)'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Create Firm Override Notification Template');
        $this->modalDescription('This creates content/metadata only — no real email transport exists in this codebase, and this action never sends anything.');

        $this->action(function (array $data, NotificationTemplateService $templateService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageNotificationTemplates($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage notification templates.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $data['firm_uuid']);

            $template = $templateService->createFirmOverride(
                $firm,
                (string) $data['key'],
                ConsentChannel::from((string) $data['channel']),
                (string) $data['body'],
                (string) ($data['language'] ?? 'en'),
                $data['subject'] !== '' ? ($data['subject'] ?? null) : null,
                $data['from_email'] !== '' ? ($data['from_email'] ?? null) : null,
                $data['from_domain'] !== '' ? ($data['from_domain'] ?? null) : null,
                $admin,
            );

            Notification::make()
                ->title('Firm override template created')
                ->body("{$template->key} ({$template->channel->value}) for {$firm->name}.")
                ->success()
                ->send();
        });
    }
}
