<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Filament\Support\Integrations\ProviderKillSwitchScope;
use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Integrations\Models\ProviderKillSwitch;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ToggleProviderKillSwitchAction — releases an active provider kill
 * switch, or re-suspends a released one.
 *
 * Replaces the previous inline `toggleAction()` on
 * ProviderKillSwitchResource, which was a bare requiresConfirmation()
 * flip: no reason, no step-up, no audit row, and — most importantly —
 * no statement of what resuming would allow to start flowing again.
 * Mission §69: "Never use casual one-click recovery for high-impact
 * suspension."
 *
 * Both directions are governed identically (release and re-suspend are
 * both high-impact state changes on a live payment/banking integration),
 * and both write a distinct, queryable security_events row through the
 * canonical PlatformAdminAuditEventRecorder, carrying the previous and
 * new state explicitly (§24) rather than leaving an auditor to infer the
 * transition from a timestamp.
 *
 * TOCTOU discipline: the record's current `suspended` value is re-read
 * from the passed model inside the closure and the transition is
 * computed there — never trusted from what the table happened to render
 * when the page loaded.
 */
class ToggleProviderKillSwitchAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'toggleProviderKillSwitch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (ProviderKillSwitch $record): string => $record->suspended ? 'Release' : 'Re-suspend');
        $this->icon(fn (ProviderKillSwitch $record): Heroicon => $record->suspended ? Heroicon::OutlinedPlayCircle : Heroicon::OutlinedStopCircle);
        $this->color(fn (ProviderKillSwitch $record): string => $record->suspended ? 'success' : 'danger');

        $this->modalHeading(fn (ProviderKillSwitch $record): string => $record->suspended
            ? 'Release this kill switch'
            : 'Re-suspend this kill switch');

        $this->modalDescription(fn (ProviderKillSwitch $record): string => $record->suspended
            ? 'Releasing this switch immediately allows the suspended provider calls to resume for every firm on this platform. '
                .'Confirm the underlying incident is genuinely resolved — there is no staged or partial release.'
            : 'Re-suspending immediately refuses the matching provider calls again for every firm on this platform.');

        StepUpAuthentication::mergeInto($this, fn (): array => [
            Placeholder::make('scope_summary')
                ->label('What this switch controls')
                ->content(fn (ProviderKillSwitch $record): string => sprintf(
                    '%s · level: %s · target: %s · scope: platform-wide',
                    IntegrationDisplay::labelForProviderCode($record->provider_key),
                    $record->level,
                    $record->target,
                )),
            Placeholder::make('enforcement')
                ->label('Where it is enforced')
                ->content(fn (ProviderKillSwitch $record): string => ProviderKillSwitchScope::enforcementDisclosure($record->level)),
            Placeholder::make('impact')
                ->label('Measured impact')
                ->content(fn (ProviderKillSwitch $record): string => ProviderKillSwitchScope::impactSentence((string) $record->provider_key)),
            Textarea::make('reason')
                ->label('Reason for this change')
                ->rows(2)
                ->required()
                ->helperText('Recorded on the platform audit trail with the previous and new state.'),
        ], 'platform_admin');

        $this->action(function (ProviderKillSwitch $record, array $data): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $accessPolicy = app(PlatformStaffAccessPolicyService::class);

            if (! $accessPolicy->canAccessIntegrationOversight($admin)->allowed) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $reason = trim((string) ($data['reason'] ?? ''));

            if ($reason === '') {
                Notification::make()->title('A reason is required.')->danger()->send();

                return;
            }

            // Re-read state at action time, never from render time.
            $wasSuspended = (bool) $record->fresh()?->suspended;
            $nowSuspended = ! $wasSuspended;

            $record->update([
                'suspended' => $nowSuspended,
                'reason' => $reason,
                'suspended_by' => $admin->id,
                'suspended_at' => now(),
            ]);

            app(PlatformAdminAuditEventRecorder::class)->recordPlatformEvent(
                $admin,
                $nowSuspended ? 'provider_kill_switch_activated' : 'provider_kill_switch_released',
                CreateProviderKillSwitchAction::AUDIT_CATEGORY,
                [
                    'provider_kill_switch_id' => $record->id,
                    'provider_key' => $record->provider_key,
                    'level' => $record->level,
                    'target' => $record->target,
                    'scope_type' => $record->scope_type,
                    'previous_state' => $wasSuspended ? 'suspended' : 'released',
                    'new_state' => $nowSuspended ? 'suspended' : 'released',
                    'reason' => $reason,
                    'measured_impact' => ProviderKillSwitchScope::impactPreview((string) $record->provider_key),
                ],
            );

            Notification::make()
                ->title($nowSuspended ? 'Kill switch re-suspended' : 'Kill switch released')
                ->success()
                ->send();
        });
    }
}
