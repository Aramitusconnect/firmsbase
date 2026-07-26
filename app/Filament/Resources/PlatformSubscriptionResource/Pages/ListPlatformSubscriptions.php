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
}
