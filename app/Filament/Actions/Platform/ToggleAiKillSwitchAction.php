<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\AiPolicySettingService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ToggleAiKillSwitchAction — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 14. PlatformAiOversightPage's own sole mutating
 * action. Reads current state fresh (never trusts what the page
 * rendered at load time — TOCTOU-safe, mirroring
 * RunHealthChecksNowAction's own established shape) and flips
 * ai_policy_settings['platform_ai_enabled'] via the existing
 * AiPolicySettingService::set() — the same audited write path
 * EditAiPolicySettingValueAction already uses, just with a real
 * boolean toggle instead of raw JSON editing.
 *
 * This is the ONLY genuine UI path that can engage/disengage the
 * platform AI kill switch at all — before this checkpoint, no row for
 * 'platform_ai_enabled' was ever seeded or editable through any real
 * UI (AiPolicySettingResource's own row-edit action can only ever act
 * on an already-existing row). Deliberately does NOT change the
 * existing absent-row-means-enabled default (see
 * AiModeResolutionService::platformKillSwitchEngaged()'s own
 * docblock) — an environment that has never touched this setting
 * behaves exactly as it did before this checkpoint; this page only
 * adds the missing lever, it does not flip anything on its own.
 * Every use is confirmed and audited via AiPolicySettingService's own
 * security_events write.
 */
class ToggleAiKillSwitchAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'toggleAiKillSwitch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => app(AiModeResolutionService::class)->platformKillSwitchEngaged()
            ? 'Enable platform AI'
            : 'Disable platform AI (kill switch)');
        $this->icon(fn (): Heroicon => app(AiModeResolutionService::class)->platformKillSwitchEngaged()
            ? Heroicon::OutlinedPlayCircle
            : Heroicon::OutlinedStopCircle);
        $this->color(fn (): string => app(AiModeResolutionService::class)->platformKillSwitchEngaged() ? 'success' : 'danger');

        $this->requiresConfirmation();
        $this->modalHeading('Change platform AI availability');
        $this->modalDescription(
            'This is the single platform-wide gate every AI call in the system goes through, checked before any '.
            'firm\'s own mode/entitlement/keys. Disabling it halts every AI call immediately, regardless of firm '.
            'configuration; enabling it does not itself launch or promote anything — firm-level opt-in and '.
            'entitlement gates still apply on top of this.'
        );

        $this->action(function (AiModeResolutionService $aiMode, AiPolicySettingService $settingService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageAiPolicySettings($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage AI policy settings.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $currentlyEngaged = $aiMode->platformKillSwitchEngaged();
            $settingService->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, $currentlyEngaged, $admin);

            Notification::make()
                ->title($currentlyEngaged ? 'Platform AI enabled' : 'Platform AI disabled')
                ->success()
                ->send();
        });
    }
}
