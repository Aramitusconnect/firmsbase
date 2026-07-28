<?php

declare(strict_types=1);

namespace App\Filament\Firm\Widgets;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Services\PlaidEntitlementPolicyService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * PlaidFirmOverviewSummaryCardsWidget — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). Mirrors
 * `PlatformIntegrationOverviewSummaryCardsWidget`'s single-bounded-
 * aggregate-query idiom, scoped to this firm.
 */
class PlaidFirmOverviewSummaryCardsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm);
    }

    protected function getStats(): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        $items = FirmIntegration::query()
            ->where('firm_id', $firmUser->firm_id)
            ->whereHas('integrationProvider', fn ($q) => $q->where('code', ProviderKey::Plaid->value))
            ->get(['status']);

        $active = $items->filter(fn ($i) => (is_object($i->status) ? $i->status->value : $i->status) === 'active')->count();
        $attention = $items->filter(fn ($i) => in_array(is_object($i->status) ? $i->status->value : $i->status, ['reauthorization_required', 'error', 'scope_insufficient'], true))->count();

        return [
            Stat::make('Connected Items', (string) $active)->icon(Heroicon::OutlinedLink)->color('success'),
            Stat::make('Needs Attention', (string) $attention)->icon(Heroicon::OutlinedExclamationTriangle)->color($attention > 0 ? 'warning' : 'success'),
            Stat::make('Total Items', (string) $items->count())->icon(Heroicon::OutlinedBuildingLibrary),
        ];
    }
}
