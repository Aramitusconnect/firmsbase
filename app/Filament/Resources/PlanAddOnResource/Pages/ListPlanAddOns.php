<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanAddOnResource\Pages;

use App\Filament\Actions\Platform\AddPlanModuleAction;
use App\Filament\Resources\PlanAddOnResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlanAddOns — FIRMSVAULT STAGING ADMIN STABILIZATION: registers
 * the one supported way to attach a module to a plan, AddPlanModuleAction
 * (a purpose-built header action routing through PlanModuleService::
 * addModule(), not a bare Eloquent create form — see that action's own
 * docblock). Per-record mutations (Enable/Disable/Retire) remain list
 * row actions and View page header actions.
 */
class ListPlanAddOns extends ListRecords
{
    protected static string $resource = PlanAddOnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddPlanModuleAction::make(),
        ];
    }

    /**
     * Billing & Commercial Control Plane pass. Two things an operator
     * would otherwise assume, both wrong, stated up front.
     *
     * 1. ADD-ONS HAVE NO PRICE OF THEIR OWN. Verified against the
     *    plan_modules migration at this pass's HEAD: the table has
     *    plan_id, module_code, enabled, is_addon, and status — no
     *    price, rate, or amount column of any kind. An add-on row
     *    grants a module; the money side of a subscription is its
     *    plan's price plus its subscription line items, which are a
     *    different table entirely. Any "add-on pricing" shown here
     *    would be invented.
     *
     * 2. THERE IS NO DEPENDENCY MODEL. module_catalog has module_code,
     *    module_name, category, description, is_active, and
     *    requires_admin_approval — and nothing expressing requires,
     *    conflicts-with, included-with, or replaces. So no dependency
     *    or incompatibility can be displayed, checked, or enforced, and
     *    disabling an add-on cannot be blocked on one. Building
     *    UI-only dependency rules here would be worse than having none:
     *    they would be enforced in the console and silently absent
     *    everywhere else.
     *
     * 3. NO EFFECTIVE DATING. plan_modules has no effective-from or
     *    effective-until column, so enable/disable/retire take effect
     *    on the catalog immediately and cannot be scheduled.
     */
    public function getSubheading(): ?string
    {
        return 'Add-ons are plan modules flagged as add-ons — there is no separate add-on entity, and an add-on '.
            'carries no price of its own. This domain models no dependencies between modules (no requires, '.
            'conflicts-with, included-with, or replaces), so none is shown or enforced, and no change here can be '.
            'scheduled for a future date. Catalog changes do not retroactively alter any firm\'s current '.
            'entitlements — a firm picks up a plan\'s add-on configuration the next time its licence is '.
            'assigned that plan.';
    }
}
