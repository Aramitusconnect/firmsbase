<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformSubscriptionResource\Pages;

use App\Filament\Resources\PlatformSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformSubscriptions — no header actions: PlatformSubscription
 * rows are created exclusively via
 * PlatformSubscriptionService::subscribe() as part of a larger
 * commercial-onboarding workflow, out of this checkpoint's scope (see
 * PlatformSubscriptionResource's own docblock). No CreateAction, no
 * Create page, mirroring every other Phase 1-3 oversight Resource's own
 * "no Create/Edit forms" ruling. Mutation (Cancel) happens per-record,
 * both as a list row action and a View page header action.
 */
class ListPlatformSubscriptions extends ListRecords
{
    protected static string $resource = PlatformSubscriptionResource::class;

    /**
     * Billing & Commercial Control Plane pass. States the ACTUAL
     * lifecycle surface of this domain, so an operator is not left
     * hunting for a "Change plan" or "Pause" action that no service
     * implements.
     *
     * Verified against PlatformSubscriptionService at this pass's HEAD:
     * it exposes subscribe(), cancel(atPeriodEnd), and addItem(), and
     * nothing else. There is no plan change, scheduled plan change,
     * pause, resume, cancellation-resume, add-on attach/detach on a live
     * subscription, or proration calculation anywhere in this codebase.
     */
    public function getSubheading(): ?string
    {
        return 'FirmsVault\'s own subscriptions to its customer firms — not a firm\'s client payment plans. The '.
            'only lifecycle changes this domain supports are cancel at period end and cancel immediately. There '.
            'is no plan change, scheduled plan change, pause, resume, resume-cancellation, or proration, so none '.
            'is offered here. A subscription carries no price of its own: its amount is its plan\'s current price '.
            'plus its line items, and a plan\'s price is locked once any subscription references it.';
    }
}
