<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Services\NotificationTemplateService;
use App\Services\PlatformNotificationTemplateDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * RevertFirmNotificationTemplateOverrideAction — stands a firm's own
 * version of a template down so the global default applies to that firm
 * again (mission section 68).
 *
 * Only ever offered for a FIRM OVERRIDE row. Reverting is implemented
 * as archiving the firm row — resolve() only matches Active rows, so
 * the global default takes over immediately — and never as a delete,
 * so the evidence that the firm once had its own version survives. The
 * global default itself is never read, written or archived by this
 * action; NotificationTemplateService::revertFirmOverride() refuses a
 * global default outright, so this cannot become the accidental way to
 * remove the fallback every firm depends on.
 *
 * The confirmation shows which global default (if any) will take over,
 * because "revert" is only safe if there is something to revert TO —
 * and a firm with no global default for that (key, channel, language)
 * would end up with NO template at all, which the operator must see
 * before confirming, not discover afterwards.
 */
class RevertFirmNotificationTemplateOverrideAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revertFirmNotificationTemplateOverride';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revert to global');
        $this->icon(Heroicon::OutlinedArrowUturnLeft);
        $this->color('warning');

        $this->requiresConfirmation();
        $this->modalHeading('Revert firm override to the global default');

        $this->visible(fn (array $record): bool => self::isRevertable($record));

        $this->schema([
            Placeholder::make('revert_effect')
                ->label('What this does')
                ->content(fn (array $record): string => self::effectFor($record)),
        ]);

        $this->action(function (array $record, PlatformNotificationTemplateDirectoryService $directory, NotificationTemplateService $templateService, PlatformStaffAccessPolicyService $accessPolicy): void {
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

            $firm = $record['firm_id'] === null ? null : Firm::query()->find($record['firm_id']);

            if ($firm === null) {
                Notification::make()
                    ->title('Not a firm override')
                    ->body('Only a firm-specific override can be reverted. A global default has nothing to revert to.')
                    ->danger()
                    ->send();

                return;
            }

            $template = $directory->findModel($admin, $firm, (int) $record['id']);

            if ($template === null) {
                Notification::make()->title('That notification template could not be found.')->danger()->send();

                return;
            }

            try {
                $reverted = $templateService->revertFirmOverride($template, $admin);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not revert')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Firm override reverted')
                ->body("{$reverted->key} ({$reverted->channel->value}) — this firm now resolves to the global default. The override row is retained as history.")
                ->success()
                ->send();
        });
    }

    /**
     * Whether this row is a firm override that can still be reverted.
     *
     * Public and static so the rule can be asserted directly, without
     * going through Filament's table plumbing — which matters here
     * because the list deliberately shows GLOBAL DEFAULTS ONLY when no
     * firm filter is selected, so a firm override row is not present on
     * the default table to assert against at all.
     *
     * A global default is excluded because it has nothing to revert TO;
     * an already-archived override because it has nothing left to
     * revert. This is a UI convenience only — the action re-checks
     * server-side and NotificationTemplateService::revertFirmOverride()
     * refuses a global default regardless.
     *
     * @param  array<string, mixed>  $record
     */
    public static function isRevertable(array $record): bool
    {
        return ($record['firm_id'] ?? null) !== null
            && ($record['status'] ?? null) !== NotificationTemplateStatus::Archived->value;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function effectFor(array $record): string
    {
        $key = (string) ($record['key'] ?? '');
        $channel = (string) ($record['channel'] ?? '');
        $language = (string) ($record['language'] ?? 'en');

        // The global default is a firm_id IS NULL row, readable from a
        // zero-context session under this table's dual-policy RLS
        // design — no tenant context needed to check for it.
        $global = NotificationTemplate::query()
            ->whereNull('firm_id')
            ->where('key', $key)
            ->where('channel', $channel)
            ->where('language', $language)
            ->where('status', NotificationTemplateStatus::Active->value)
            ->first(['id', 'subject']);

        $channelLabel = ConsentChannel::tryFrom($channel)?->value ?? $channel;

        if ($global === null) {
            return sprintf(
                'WARNING: there is no active global default for (%s, %s, %s). '
                .'Reverting would leave this firm with NO template for this notification at all. '
                .'The override row is archived, never deleted, so it remains as history.',
                $key,
                $channelLabel,
                $language,
            );
        }

        return sprintf(
            'This firm will fall back to the active global default for (%s, %s, %s)%s. '
            .'The firm override row is archived, never deleted, and the global default is not modified.',
            $key,
            $channelLabel,
            $language,
            $global->subject !== null ? ' — subject: "'.$global->subject.'"' : '',
        );
    }
}
