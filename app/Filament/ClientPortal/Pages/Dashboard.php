<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Enums\MatterStatus;
use App\Filament\ClientPortal\Resources\MatterResource;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.3. The Client Portal panel had no landing page at all
 * before this — auto-discovered from
 * `app/Filament/ClientPortal/Pages` (ClientPortalPanelProvider's own
 * `discoverPages()` call, unedited) with the inherited `$routePath =
 * '/'` from the base `Filament\Pages\Dashboard` this extends, making
 * it the panel's root route with no separate registration needed.
 *
 * Deliberately small — a landing page, not a data-dense screen, per
 * this mission's own instruction. Two simple stats, mirroring
 * `AccountingOverviewPage`'s own "Section + Text, service-computed
 * summary string" shape rather than a generic widget grid:
 *
 *   - Active matters: counted through
 *     `ClientPortalMatterAccessPolicyService::grantedMatterIds()` only
 *     — never inferred from `Matter.client_id` alone.
 *   - Pending financial data requests: the identical query shape
 *     `PlaidRequestReviewPage` itself uses (read-only reference, not
 *     edited by this mission — see that page's own docblock), reused
 *     here rather than duplicated as a new service method for a single
 *     dashboard number.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    public function getColumns(): int|array
    {
        return 2;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Your Matters')
                ->schema([
                    Text::make(fn (): string => $this->activeMattersSummary())->size(TextSize::Large),
                ]),
            Section::make('Financial Data Requests')
                ->schema([
                    Text::make(fn (): string => $this->pendingFinancialRequestsSummary())->size(TextSize::Large),
                ]),
        ]);
    }

    private function activeMattersSummary(): string
    {
        $portalUser = $this->currentPortalUser();

        if ($portalUser === null) {
            return 'No active session.';
        }

        $count = MatterResource::getEloquentQuery()
            ->whereNotIn('status', [MatterStatus::Closed->value, MatterStatus::Archived->value])
            ->count();

        return $count === 1 ? '1 active matter shared with you.' : "{$count} active matters shared with you.";
    }

    private function pendingFinancialRequestsSummary(): string
    {
        $portalUser = $this->currentPortalUser();

        if ($portalUser === null) {
            return 'No active session.';
        }

        $grantedMatterIds = app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser);

        if ($grantedMatterIds === []) {
            return 'No pending financial data requests.';
        }

        $count = (new TenantContextService)->runWithFirmContext(
            $portalUser->client->firm_id,
            fn () => FinancialEvidenceMatterRequest::query()
                ->whereIn('matter_id', $grantedMatterIds)
                ->where('status', 'pending')
                ->count(),
        );

        return $count === 0
            ? 'No pending financial data requests.'
            : ($count === 1 ? '1 pending financial data request.' : "{$count} pending financial data requests.");
    }

    private function currentPortalUser(): ?ClientPortalUser
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        return $portalUser;
    }
}
