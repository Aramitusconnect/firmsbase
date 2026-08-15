<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use League\Csv\Writer;

/**
 * ExportFirmInventoryCsvAction — CORE SuperAdmin mission (admin/core-
 * superadmin-security), Phase 5, section 61: "add a safe Firm inventory
 * export ONLY if the existing architecture already supports a safe,
 * authorized export pattern." It does: this mirrors the established
 * League\Csv + response()->streamDownload() pattern already used twice
 * elsewhere (PlatformIntegrationOverviewPage::exportCsvAction() and
 * DownloadImportBatchErrorCsvAction) — no new export mechanism, no new
 * package, no new migration, no queued-export subsystem.
 *
 * Exports exactly the columns FirmResource::table() already displays
 * to any admin who can reach the Firms list (name, activation_status,
 * customer_type, deployment_mode, created_at) plus the firm's uuid
 * (needed to unambiguously identify a row, and already exposed via
 * FirmResource\Pages\ViewFirm) — nothing beyond what a platform admin
 * with Firms-list access already sees on screen, so this introduces no
 * new data exposure. `firms` carries no BelongsToTenant/RLS (it is the
 * tenancy root — see FirmResource's own docblock), so an ordinary
 * Eloquent query here needs no tenant-context wrapping, exactly like
 * the table it mirrors.
 *
 * Bounded (EXPORT_ROW_LIMIT), never a second divergent query shape from
 * the list page, and gated by the exact same
 * canAccessPlatformAdministration() check FirmResource::canAccess()
 * itself delegates to via FirmPolicy::viewAny() — re-verified inside
 * the action closure (TOCTOU discipline), never trusted from
 * ->visible() alone.
 */
class ExportFirmInventoryCsvAction extends Action
{
    private const EXPORT_ROW_LIMIT = 5000;

    public static function getDefaultName(): ?string
    {
        return 'exportFirmInventoryCsv';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Export CSV');
        $this->icon(Heroicon::OutlinedArrowDownTray);
        $this->color('gray');

        $this->visible(function (): bool {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                return false;
            }

            return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformAdministration($admin)->allowed;
        });

        $this->action(function () {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return null;
            }

            if (! app(PlatformStaffAccessPolicyService::class)->canAccessPlatformAdministration($admin)->allowed) {
                Notification::make()->title('Not permitted')->danger()->send();

                return null;
            }

            $firms = Firm::query()
                ->orderBy('name')
                ->limit(self::EXPORT_ROW_LIMIT)
                ->get(['uuid', 'name', 'activation_status', 'customer_type', 'deployment_mode', 'created_at']);

            $csv = Writer::createFromString('');
            $csv->insertOne(['Firm ID', 'Name', 'Activation Status', 'Customer Type', 'Deployment Mode', 'Created At']);

            foreach ($firms as $firm) {
                $csv->insertOne([
                    $firm->uuid,
                    $firm->name,
                    $firm->activation_status?->value ?? '',
                    $firm->customer_type?->value ?? '',
                    $firm->deployment_mode?->value ?? '',
                    $firm->created_at?->toIso8601String() ?? '',
                ]);
            }

            $csvContent = $csv->toString();

            return response()->streamDownload(function () use ($csvContent): void {
                echo $csvContent;
            }, 'firm-inventory-'.now()->format('Y-m-d-His').'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        });
    }
}
