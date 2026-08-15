<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Services\Configuration\EntitlementResolutionTraceService;
use App\Services\EntitlementOverrideService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * SetEntitlementOverrideAction — EntitlementOverrideResource's header
 * action (registered on ListEntitlementOverrides, not a per-row action
 * — a new override may target a module the firm has no existing
 * FirmEntitlement row for at all, so this deliberately takes an
 * explicit firm + module_code selection rather than assuming an
 * existing row to edit). Calls
 * EntitlementOverrideService::setOverrideAsPlatformAdmin() — the
 * PlatformAdmin-actor variant this phase added — never the pre-existing
 * setOverride(User $actor) directly (see that method's own docblock for
 * why a PlatformAdmin cannot safely be forced through the User-typed
 * path).
 *
 * Only FirmOverride/AdminOverride sources are offered — mirrors
 * EntitlementOverrideService's own validation (Plan/OrgInherited are
 * never written through this path, by design: those are Phase 6/plan-
 * sync-owned sources).
 */
class SetEntitlementOverrideAction extends Action
{
    public const DURATION_TEMPORARY = 'temporary';

    public const DURATION_PERMANENT = 'permanent';

    public static function getDefaultName(): ?string
    {
        return 'setEntitlementOverride';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Set Override');
        $this->icon(Heroicon::OutlinedAdjustmentsHorizontal);
        $this->color('primary');

        $this->schema([
            Select::make('firm_uuid')
                ->label('Firm')
                ->searchable()
                ->required()
                ->native(false)
                ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
            Select::make('module_code')
                ->label('Module')
                ->searchable()
                ->required()
                ->native(false)
                ->options(fn (): array => ModuleCatalog::query()->orderBy('module_name')->pluck('module_name', 'module_code')->all()),
            Select::make('source')
                ->label('Override source')
                ->required()
                ->native(false)
                ->live()
                ->options([
                    EntitlementSource::FirmOverride->value => 'Firm override',
                    EntitlementSource::AdminOverride->value => 'Platform admin override',
                ])
                ->helperText('Precedence (highest wins): Platform admin override › Firm override › Organization inherited › Plan.'),
            Toggle::make('enabled')
                ->label('Enabled')
                ->default(true)
                ->live(),
            /**
             * Mission section 44: show the CURRENT effective resolution
             * before the operator confirms a change to it. Built from
             * the canonical resolver via EntitlementResolutionTraceService
             * — never a second precedence computation.
             */
            Placeholder::make('current_resolution')
                ->label('Current effective resolution')
                ->content(fn (callable $get): string => self::currentResolutionFor($get))
                ->visible(fn (callable $get): bool => filled($get('firm_uuid')) && filled($get('module_code'))),
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(2),
            /**
             * Mission section 45: a blank end date must never
             * ACCIDENTALLY mean permanent. There is deliberately no
             * default here — the operator must choose, and
             * EntitlementOverrideService enforces the same rule
             * server-side regardless of what this form submitted.
             */
            Radio::make('override_duration')
                ->label('Override duration')
                ->required()
                ->live()
                ->options([
                    self::DURATION_TEMPORARY => 'Temporary — expires automatically on a date you choose',
                    self::DURATION_PERMANENT => 'Permanent — stays in effect until someone explicitly revokes it',
                ]),
            DateTimePicker::make('ends_at')
                ->label('Ends at')
                ->native(false)
                ->seconds(false)
                ->minDate(now()->addMinute())
                ->helperText('After this moment the override stops applying and entitlement resolution falls back to the next-highest source.')
                ->visible(fn (callable $get): bool => $get('override_duration') === self::DURATION_TEMPORARY)
                ->required(fn (callable $get): bool => $get('override_duration') === self::DURATION_TEMPORARY),
            Checkbox::make('permanent_acknowledged')
                ->label('I confirm this override should remain in effect permanently, until explicitly revoked.')
                ->visible(fn (callable $get): bool => $get('override_duration') === self::DURATION_PERMANENT)
                ->accepted(fn (callable $get): bool => $get('override_duration') === self::DURATION_PERMANENT),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Set Entitlement Override');
        $this->modalDescription('This creates or replaces the entitlement record for this exact (firm, module, source) combination — it does not affect records for other sources on the same module. Precedence resolution at read time decides which source currently wins.');

        $this->action(function (array $data, EntitlementOverrideService $overrideService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageEntitlementOverrides($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage entitlement overrides.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $data['firm_uuid']);
            $source = EntitlementSource::from((string) $data['source']);

            // A permanent override carries no end date by definition —
            // never trust a stale ends_at left behind by toggling the
            // duration radio back and forth.
            $isPermanent = ($data['override_duration'] ?? null) === self::DURATION_PERMANENT;
            $endsAt = $isPermanent ? null : ($data['ends_at'] ?? null);

            try {
                $entitlement = $overrideService->setOverrideAsPlatformAdmin(
                    $firm,
                    (string) $data['module_code'],
                    $source,
                    (bool) ($data['enabled'] ?? true),
                    (string) $data['reason'],
                    $admin,
                    $endsAt !== null ? Carbon::parse($endsAt) : null,
                    permanentAcknowledged: $isPermanent ? (bool) ($data['permanent_acknowledged'] ?? false) : null,
                );
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not set override')->body($e->getMessage())->danger()->send();

                return;
            }

            $duration = $entitlement->ends_at === null
                ? 'permanently, until revoked'
                : 'until '.$entitlement->ends_at->toDayDateTimeString();

            Notification::make()
                ->title('Entitlement override set')
                ->body(sprintf(
                    '%s is now %s for %s (%s), %s.',
                    app(EntitlementResolutionTraceService::class)->moduleName($entitlement->module_code),
                    $entitlement->enabled ? 'enabled' : 'disabled',
                    $firm->name,
                    app(EntitlementResolutionTraceService::class)->sourceLabel($source),
                    $duration,
                ))
                ->success()
                ->send();
        });
    }

    /**
     * Renders the current effective resolution for the selected (firm,
     * module) pair, so the operator sees what they are about to change
     * before confirming (mission section 44).
     */
    private static function currentResolutionFor(callable $get): string
    {
        $firmUuid = $get('firm_uuid');
        $moduleCode = $get('module_code');

        if (! is_string($firmUuid) || ! is_string($moduleCode)) {
            return 'Select a firm and module to see the current resolution.';
        }

        $firm = Firm::findByUuid($firmUuid);

        if ($firm === null) {
            return 'That firm could not be found.';
        }

        $trace = app(EntitlementResolutionTraceService::class)->trace($firm, $moduleCode);

        $lines = [sprintf(
            'Effective now: %s (winning source: %s).',
            $trace['effective_label'],
            $trace['winning_source_label'],
        )];

        foreach ($trace['rows'] as $row) {
            $lines[] = sprintf(
                '%s — %s%s',
                $row['source_label'],
                $row['configured_state'],
                $row['present'] ? ' ('.$row['window_state'].')' : '',
            );
        }

        return implode(' • ', $lines);
    }
}
