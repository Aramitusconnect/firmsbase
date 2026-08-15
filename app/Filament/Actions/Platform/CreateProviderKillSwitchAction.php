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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

/**
 * CreateProviderKillSwitchAction — Prompt 2 (Integration Operations)
 * hardening of the single genuinely destructive control in the
 * Integrations navigation group.
 *
 * WHAT WAS WRONG BEFORE (all four are real, all four are fixed here):
 *
 *  1. FREE-TEXT TARGET. `target` was a plain TextInput. Both enforcement
 *     points match it with exact string equality, so a typo created a
 *     row that renders as an active kill switch and suspends nothing.
 *     An operator mid-incident would believe the provider was stopped.
 *     Targets are now selected from ProviderKillSwitchScope's
 *     enforcement-derived vocabulary, and re-validated server-side in
 *     the action closure — never trusted from the submitted form alone.
 *
 *  2. NO IMPACT PREVIEW. The operator was asked to suspend a provider
 *     platform-wide with no statement of how many firms and live
 *     connections that would stop. The confirmation modal now carries a
 *     measured count (§67), including an explicit disclosure when some
 *     firms could not be evaluated or the scan was truncated — an
 *     understated blast radius is worse than none.
 *
 *  3. NO STEP-UP RE-AUTHENTICATION. Halting a payment/banking provider
 *     platform-wide sat behind an ordinary confirm dialog, while
 *     changing an AI policy setting already required step-up. This now
 *     reuses the SAME canonical mechanism
 *     (StepUpAuthentication::mergeInto + the platform_admin guard) that
 *     ToggleAiKillSwitchAction and EnterSupportAccessSessionAction use —
 *     no parallel MFA is invented here (§26).
 *
 *  4. NO AUDIT WHATSOEVER. Creating a kill switch wrote a row and
 *     nothing else: no security_events entry, no actor trail beyond the
 *     `suspended_by` column, no reason preserved anywhere queryable.
 *     Every create now writes one platform-level security_events row via
 *     the canonical PlatformAdminAuditEventRecorder — the same recorder
 *     AiPolicySettingService already uses — carrying actor, provider,
 *     level, target, scope, reason, and measured impact. Never a secret,
 *     never a provider payload.
 *
 * Also fixed: the previous form let an operator pick a level whose
 * enforcement does not cover their provider without saying so, and
 * offered no indication that firm-scoped switches are read by nothing.
 * See ProviderKillSwitchScope's own docblock for both.
 *
 * NOT changed: this action still writes `provider_kill_switches`
 * directly, because no canonical kill-switch domain service exists in
 * this codebase to route through — the model's own docblock states this
 * Resource "is the one place this table is writable". Inventing a second
 * suspension mechanism is explicitly out of scope (§64); what is added
 * here is governance around the existing write, not a replacement for it.
 */
class CreateProviderKillSwitchAction extends Action
{
    public const AUDIT_CATEGORY = 'platform_integration_oversight';

    public static function getDefaultName(): ?string
    {
        return 'createProviderKillSwitch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('New Kill Switch');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->modalHeading('Create a provider kill switch');
        $this->modalDescription(
            'A kill switch immediately refuses outbound provider calls for every firm on this platform. '
            .'It takes effect as soon as it is created — there is no staged or scheduled activation.'
        );
        $this->modalSubmitActionLabel('Create and activate');

        StepUpAuthentication::mergeInto($this, fn (): array => [
            Select::make('provider_key')
                ->label('Provider')
                ->options(fn (): array => IntegrationDisplay::liveProviderOptions())
                ->required()
                ->live(),

            Select::make('level')
                ->label('Level')
                ->options(fn (): array => ProviderKillSwitchScope::levelOptions())
                ->required()
                ->live()
                ->helperText(fn (Get $get): string => ProviderKillSwitchScope::enforcementDisclosure($get('level'))),

            // LEVEL_PROVIDER takes no operator-chosen target — the
            // enforcement point matches the provider key itself, so the
            // field is hidden and the value derived, rather than asking
            // an operator to retype a string that must match exactly.
            Select::make('target')
                ->label('Target')
                ->options(fn (Get $get): array => ProviderKillSwitchScope::targetOptions($get('level')) ?? [])
                ->required(fn (Get $get): bool => ProviderKillSwitchScope::targetOptions($get('level')) !== null)
                ->visible(fn (Get $get): bool => ProviderKillSwitchScope::targetOptions($get('level')) !== null)
                ->helperText('Only targets the enforcement code can actually match are listed. A target that is not in this list would create a switch that suspends nothing.'),

            Placeholder::make('scope_disclosure')
                ->label('Scope')
                ->content('Platform-wide. Per-firm kill switches are deliberately not offered: both enforcement points read platform-scope rows only, so a firm-scoped switch would be recorded and then ignored.'),

            Placeholder::make('impact_preview')
                ->label('Impact')
                ->content(fn (Get $get): string => filled($get('provider_key'))
                    ? ProviderKillSwitchScope::impactSentence((string) $get('provider_key'))
                    : 'Select a provider to measure how many firms and active connections this would stop.'),

            Textarea::make('reason')
                ->label('Reason for this suspension')
                ->rows(2)
                ->required()
                ->helperText('Recorded on the platform audit trail alongside the actor, provider, level, and measured impact.'),
        ], 'platform_admin');

        $this->action(function (array $data): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $accessPolicy = app(PlatformStaffAccessPolicyService::class);

            // Read gate first, then the separate mutation gate — the
            // read-only auditor role holds the former and must never
            // hold the latter (the exact gap a prior checkpoint found on
            // this very resource).
            if (! $accessPolicy->canAccessIntegrationOversight($admin)->allowed) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $providerKey = trim((string) ($data['provider_key'] ?? ''));
            $level = trim((string) ($data['level'] ?? ''));
            $reason = trim((string) ($data['reason'] ?? ''));

            // LEVEL_PROVIDER's target is derived, never operator-typed.
            $target = $level === ProviderKillSwitch::LEVEL_PROVIDER
                ? $providerKey
                : trim((string) ($data['target'] ?? ''));

            if ($providerKey === '' || $level === '' || $target === '' || $reason === '') {
                Notification::make()->title('Provider, level, target, and reason are all required.')->danger()->send();

                return;
            }

            // Server-side re-validation of the whole triple. The form's
            // own option lists are a convenience; this is the check that
            // actually holds, against a Livewire payload that is
            // client-supplied input like any other.
            if (! array_key_exists($providerKey, IntegrationDisplay::liveProviderOptions())) {
                Notification::make()->title('Unknown or unavailable provider.')->danger()->send();

                return;
            }

            if (! array_key_exists($level, ProviderKillSwitchScope::levelOptions())) {
                Notification::make()->title('Unknown kill-switch level.')->danger()->send();

                return;
            }

            if (! ProviderKillSwitchScope::isEnforceableTarget($providerKey, $level, $target)) {
                Notification::make()
                    ->title('That target would not be enforced')
                    ->body('No enforcement path matches this provider/level/target combination, so the switch would suspend nothing. Pick a target from the list.')
                    ->danger()
                    ->send();

                return;
            }

            // Duplicate-active guard (§110): a second identical active
            // row changes nothing operationally but makes the release
            // path ambiguous — an operator releasing "the" switch would
            // leave a second one silently enforcing.
            $existing = ProviderKillSwitch::query()
                ->where('provider_key', $providerKey)
                ->where('scope_type', ProviderKillSwitchScope::ENFORCED_SCOPE)
                ->whereNull('scope_id')
                ->where('level', $level)
                ->where('target', $target)
                ->where('suspended', true)
                ->first();

            if ($existing !== null) {
                Notification::make()
                    ->title('An active kill switch already exists')
                    ->body('This provider, level, and target are already suspended. Release the existing switch instead of creating a second one.')
                    ->warning()
                    ->send();

                return;
            }

            $impact = ProviderKillSwitchScope::impactPreview($providerKey);

            $switch = ProviderKillSwitch::query()->create([
                'provider_key' => $providerKey,
                'scope_type' => ProviderKillSwitchScope::ENFORCED_SCOPE,
                'scope_id' => null,
                'level' => $level,
                'target' => $target,
                'suspended' => true,
                'reason' => $reason,
                'suspended_by' => $admin->id,
                'suspended_at' => now(),
            ]);

            app(PlatformAdminAuditEventRecorder::class)->recordPlatformEvent(
                $admin,
                'provider_kill_switch_activated',
                self::AUDIT_CATEGORY,
                [
                    'provider_kill_switch_id' => $switch->id,
                    'provider_key' => $providerKey,
                    'level' => $level,
                    'target' => $target,
                    'scope_type' => ProviderKillSwitchScope::ENFORCED_SCOPE,
                    'previous_state' => 'none',
                    'new_state' => 'suspended',
                    'reason' => $reason,
                    'measured_impact' => $impact,
                ],
            );

            Notification::make()
                ->title('Kill switch active')
                ->body(IntegrationDisplay::labelForProviderCode($providerKey).' — '.$target.' is now suspended platform-wide.')
                ->success()
                ->send();
        });
    }
}
