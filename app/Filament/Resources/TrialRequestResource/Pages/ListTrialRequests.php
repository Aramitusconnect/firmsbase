<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrialRequestResource\Pages;

use App\Filament\Resources\TrialRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListTrialRequests — no header actions: no Create form (see
 * TrialRequestResource's own docblock for why). Mutations
 * (Provision/Activate/Convert/Expire) happen per-record, both as list
 * row actions and View page header actions.
 */
class ListTrialRequests extends ListRecords
{
    protected static string $resource = TrialRequestResource::class;

    /**
     * Billing & Commercial Control Plane pass. An operator looking at
     * this list has to be able to answer "what happens when one of
     * these expires?" without reading the source, and the true answer
     * is narrower than the word "expire" implies — so it is stated
     * here, on the page, rather than assumed.
     *
     * Verified against TrialRequestService::expire() at this pass's
     * HEAD: it sets status to Expired and writes an audit event. It
     * does not touch entitlements, licenses, firm state, or access of
     * any kind, and no scheduled job anywhere calls it — expiry is an
     * operator action on a record, not an enforcement mechanism.
     */
    public function getSubheading(): ?string
    {
        return 'Trial requests originate from the sales pipeline: one is created against an opportunity, never '.
            'from this console. Expiring a trial sets its status to Expired and records an audit event — it does '.
            'not disable access, impose read-only mode, start a grace period, or end a subscription, and nothing '.
            'expires trials automatically. Product access is governed by entitlements, which trial expiry does '.
            'not change.';
    }
}
