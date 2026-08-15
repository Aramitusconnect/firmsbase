<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformResellersPage — "Reseller Readiness". A structurally required
 * nav item with NO matching backend concept, following this console's
 * established rule for exactly that situation: build a safe read-only
 * status page, state the limitation plainly, fabricate nothing.
 *
 * Re-confirmed at this pass's HEAD rather than inherited from the Phase
 * 3 report: no Reseller or Partner model, migration, table, service,
 * enum, or Filament resource exists anywhere in this codebase.
 *
 * BILLING & COMMERCIAL CONTROL PLANE PASS — WHAT CHANGED AND WHY
 * -------------------------------------------------------------
 * Phase 3 built this page with TWO sections: the reseller disclosure,
 * plus an embedded CommissionEvent table labeled "Internal Sales
 * Commission Data (not a reseller/partner system)". The labeling was
 * honest, but the structure was not: real employee-commission data sat
 * under a nav item reading "Resellers", so the navigation itself
 * asserted a capability the backend does not have, and an operator
 * scanning the sidebar would reasonably conclude reseller commissions
 * were being tracked.
 *
 * The commission table therefore moved out to its own page —
 * PlatformInternalSalesCommissionsPage ("Internal Sales Commissions") —
 * where it is named for what it actually is. This page now contains
 * ONLY the readiness statement, and the nav label reads "Reseller
 * Readiness" rather than "Resellers", so nothing here implies a
 * reseller product exists.
 *
 * NO DATA IS DISPLAYED AND NO QUERY IS RUN. There is deliberately no
 * "0 resellers" metric, no empty reseller table, and no "Create
 * Reseller" affordance: a zero would read as "we have a reseller system
 * with nothing in it," which is materially false. A missing product is
 * a capability gap, not an empty dataset.
 *
 * This is a product/roadmap gap, NOT an operational incident — nothing
 * here belongs in an alert queue, and nothing on this page requires
 * operator action.
 */
class PlatformResellersPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Reseller Readiness';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = 'Reseller Readiness';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Reseller and partner management is not currently implemented. This page states that capability '.
            'boundary; it does not display reseller data, because none exists.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reseller/partner management is not currently implemented')
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->schema([
                    Text::make(
                        'This platform has no reseller or partner account system. There is no reseller entity, no '.
                        'reseller user, no reseller-owned customer firm, no reseller pricing or discount, no '.
                        'revenue share, no payout, and no white-label or custom-domain configuration anywhere in '.
                        'this codebase — confirmed by a repository-wide search at this release, not carried over '.
                        'from an earlier report.'
                    )->color('danger'),
                    Text::make(
                        'Nothing on this page is a metric. In particular there is no "0 resellers" figure: a zero '.
                        'would mean a reseller system exists and is empty, which is not the case. No reseller can '.
                        'be created, assigned firms, or paid from this console.'
                    ),
                    Text::make(
                        'This is a product capability gap, not an operational problem. It does not appear in the '.
                        'Requires Attention queue on the Billing & Commercial Overview, because there is no '.
                        'operator action that would resolve it — building the domain is a separate engineering '.
                        'effort, not an admin task.'
                    ),
                ]),
            Section::make('Internal sales commissions are a different thing')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->schema([
                    Text::make(
                        'This platform does track sales commission — but for FirmsVault employees, not external '.
                        'partners. A CommissionEvent attributes commission to a PlatformAdmin (a FirmsVault staff '.
                        'member) for closing or expanding a platform deal. That is internal compensation and is '.
                        'never billed to a customer.'
                    ),
                    Text::make(
                        'Employee commission is not reseller revenue share, and this console does not present it '.
                        'as such. It lives on its own page — "Internal Sales Commissions" in this same navigation '.
                        'group — under its real name.'
                    ),
                ]),
            Section::make('What a real reseller domain would require')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'Recorded here so the gap is legible rather than rediscovered. A reseller-as-a-service '.
                        'capability needs, at minimum: a reseller organization entity with contract and suspension '.
                        'state; reseller users with their own roles and their own authentication boundary; an '.
                        'explicit assignment of which customer firms a reseller owns; which plans a reseller may '.
                        'sell and at what pricing or discount; revenue-share and commission rules distinct from '.
                        'the internal employee rules above; a payout ledger; branding and custom-domain '.
                        'configuration; scoped provisioning, support, and billing-ownership permissions; scoped '.
                        'control-plane/API access; and an audit trail over all of it.'
                    ),
                    Text::make(
                        'Access model, when it is built: resellers operate through a scoped portal and scoped APIs '.
                        'against this centrally hosted platform. Distributing source code or standing up '.
                        'reseller-run instances is not the intended model and should not be assumed by any future '.
                        'design.'
                    ),
                    Text::make(
                        'None of this is built here. This page is a disclosure, not a partial implementation.'
                    ),
                ]),
        ]);
    }
}
