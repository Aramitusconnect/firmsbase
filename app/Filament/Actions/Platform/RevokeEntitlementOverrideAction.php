<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\Configuration\EntitlementResolutionTraceService;
use App\Services\EntitlementOverrideService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * RevokeEntitlementOverrideAction — ends an override NOW, so entitlement
 * resolution falls back to whatever the next-highest source says.
 * Mission section 46 requires a revoke path; before this action the
 * console could only ever create or replace an override, never stand one
 * down, which made a permanent override effectively irreversible from
 * the UI.
 *
 * HOW IT REVOKES, AND WHY THAT WAY: it sets `ends_at` to now through the
 * existing EntitlementOverrideService, rather than deleting the row.
 * That is deliberate on three counts:
 *
 *   1. The canonical resolver already treats a past `ends_at` as out of
 *      its active window (FirmEntitlement::isWithinActiveWindow()), so
 *      an ended override stops winning immediately with no new
 *      precedence logic anywhere.
 *   2. Deleting would destroy the historical evidence that the override
 *      ever existed — mission section 47 requires history be preserved,
 *      and firm_entitlement_events rows reference the entitlement.
 *   3. It reuses the one canonical write chokepoint
 *      (EntitlementService::setForSource() via the override service),
 *      so the revocation is audited exactly like any other override
 *      write, with no second mutation path.
 *
 * ONLY OVERRIDES ARE REVOCABLE HERE. A Plan- or OrgInherited-derived
 * row is not an override and must never be edited as one (mission
 * sections 46/122) — those sources belong to the commercial catalog
 * that Prompt 4 owns. This action refuses them, and the underlying
 * service refuses them again.
 */
class RevokeEntitlementOverrideAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeEntitlementOverride';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');

        // Only ever offered for a real override row. This is a UI
        // convenience; the authorization and the source check are both
        // re-applied server-side below.
        $this->visible(fn (array $record): bool => self::isOverrideRow($record));

        $this->schema([
            Placeholder::make('revocation_effect')
                ->label('What this does')
                ->content(fn (array $record): string => self::effectFor($record)),
            Textarea::make('reason')
                ->label('Reason for revoking')
                ->required()
                ->rows(2),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Revoke entitlement override');

        $this->action(function (array $data, array $record, EntitlementOverrideService $overrideService, PlatformStaffAccessPolicyService $accessPolicy): void {
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

            if (! self::isOverrideRow($record)) {
                Notification::make()
                    ->title('Not an override')
                    ->body('Only firm and platform admin overrides can be revoked. Plan- and organization-derived entitlements are owned by the commercial catalog and are not editable here.')
                    ->danger()
                    ->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $source = EntitlementSource::from((string) $record['source']);

            try {
                $overrideService->revokeOverrideAsPlatformAdmin(
                    $firm,
                    (string) $record['module_code'],
                    $source,
                    (string) $data['reason'],
                    $admin,
                );
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not revoke override')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Override revoked')
                ->body('Entitlement resolution now falls back to the next-highest source for this module.')
                ->success()
                ->send();
        });
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function isOverrideRow(array $record): bool
    {
        return in_array(
            $record['source'] ?? null,
            [EntitlementSource::FirmOverride->value, EntitlementSource::AdminOverride->value],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function effectFor(array $record): string
    {
        $firm = Firm::findByUuid((string) ($record['firm_uuid'] ?? ''));

        if ($firm === null) {
            return 'The resulting resolution could not be previewed — that firm could not be found.';
        }

        $trace = app(EntitlementResolutionTraceService::class);
        $current = $trace->trace($firm, (string) $record['module_code']);

        // Describe what the NEXT-highest in-effect source says, since
        // that is what takes over once this override ends.
        $revokedSource = $record['source'] ?? null;

        foreach ($current['rows'] as $row) {
            if ($row['source']->value === $revokedSource || ! $row['present']) {
                continue;
            }

            if ($row['window_state'] === 'Expired' || $row['window_state'] === 'Scheduled — not yet in effect') {
                continue;
            }

            return sprintf(
                'Ends this override immediately (the record is kept for history, never deleted). Resolution then falls back to %s, which is currently %s.',
                $row['source_label'],
                strtolower($row['configured_state']),
            );
        }

        return 'Ends this override immediately (the record is kept for history, never deleted). '
            .'No other entitlement source is currently in effect for this module, so the firm will resolve to NOT ENTITLED.';
    }
}
