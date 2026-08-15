<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ConsentChannel;
use App\Filament\Support\StepUp\StepUpAuthentication;
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
 * CreateGlobalDefaultNotificationTemplateAction —
 * NotificationTemplateResource's header action. Calls
 * NotificationTemplateService::createGlobalDefault(..., $actor) — the
 * actor/audit plumbing this phase added to that method. This console
 * manages template CONTENT/METADATA only — no real email transport
 * exists anywhere in this codebase (app/Mail does not exist, no
 * Mailable subclass exists outside vendor/); this action never implies
 * a live-send capability.
 */
class CreateGlobalDefaultNotificationTemplateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createGlobalDefaultNotificationTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create Global Default');
        $this->icon(Heroicon::OutlinedGlobeAlt);
        $this->color('primary');

        /**
         * Mission section 80: a GLOBAL DEFAULT is the template every
         * firm without its own override falls back to, so creating one
         * is a platform-wide content change. Protected with fresh
         * re-authentication through the existing canonical
         * StepUpAuthentication mechanism — never a second MFA
         * implementation. The firm-override variant of this action is
         * deliberately NOT step-up protected: its blast radius is a
         * single firm.
         */
        StepUpAuthentication::mergeInto($this, [
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
        ], 'platform_admin');

        $this->requiresConfirmation();
        $this->modalHeading('Create Global Default Notification Template');
        $this->modalDescription('This creates content and metadata only. This action never sends anything, and the templated dispatch path performs no send.');

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

            $template = $templateService->createGlobalDefault(
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
                ->title('Global default template created')
                ->body("{$template->key} ({$template->channel->value}).")
                ->success()
                ->send();
        });
    }
}
