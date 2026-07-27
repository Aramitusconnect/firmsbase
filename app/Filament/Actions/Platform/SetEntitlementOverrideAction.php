<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Services\EntitlementOverrideService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
                ->options([
                    EntitlementSource::FirmOverride->value => Str::headline(EntitlementSource::FirmOverride->value),
                    EntitlementSource::AdminOverride->value => Str::headline(EntitlementSource::AdminOverride->value),
                ])
                ->helperText('Precedence (highest wins): admin_override > firm_override > org_inherited > plan.'),
            Toggle::make('enabled')
                ->label('Enabled')
                ->default(true),
            Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->rows(2),
            DateTimePicker::make('ends_at')
                ->label('Ends at (optional)')
                ->native(false),
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
            $endsAt = $data['ends_at'] ?? null;

            $entitlement = $overrideService->setOverrideAsPlatformAdmin(
                $firm,
                (string) $data['module_code'],
                $source,
                (bool) ($data['enabled'] ?? true),
                (string) $data['reason'],
                $admin,
                $endsAt !== null ? Carbon::parse($endsAt) : null,
            );

            Notification::make()
                ->title('Entitlement override set')
                ->body("{$entitlement->module_code} is now ".($entitlement->enabled ? 'enabled' : 'disabled')." for {$firm->name} ({$source->value}).")
                ->success()
                ->send();
        });
    }
}
