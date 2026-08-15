<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedPaymentResource\Pages;

use App\Filament\Resources\FailedPaymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFailedPayments — no header actions of any kind. This is a
 * strictly read-only oversight view — see FailedPaymentResource's own
 * docblock for the full "why no Retry/Waive action exists" reasoning.
 *
 * Billing & Commercial Control Plane pass: getSubheading() carries that
 * capability disclosure PERSISTENTLY, not only in the empty state. The
 * empty-state copy alone is invisible precisely when it matters most —
 * on a page that is full of failed payments an operator is trying to
 * act on. Same getSubheading() shape already used by
 * ListDocumentChaseRules on the firm side.
 */
class ListFailedPayments extends ListRecords
{
    protected static string $resource = FailedPaymentResource::class;

    public function getSubheading(): ?string
    {
        return 'Read-only. Payment recovery is not operational — no production payment gateway is configured, so '.
            'failed platform payments cannot be retried, charged, waived, or refunded from this console. This '.
            'domain also stores no retry schedule or dunning state, so no next-retry or recovery-rate figure is '.
            'shown. These records are evidence of what was attempted, not a work queue.';
    }
}
