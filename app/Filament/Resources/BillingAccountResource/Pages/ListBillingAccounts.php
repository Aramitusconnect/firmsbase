<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingAccountResource\Pages;

use App\Filament\Resources\BillingAccountResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListBillingAccounts — no header actions. Billing accounts are created
 * during commercial onboarding, not from the billing oversight console;
 * see BillingAccountResource's own docblock for why this Resource is
 * read-only.
 */
class ListBillingAccounts extends ListRecords
{
    protected static string $resource = BillingAccountResource::class;

    public function getSubheading(): ?string
    {
        return 'The bill-to entities FirmsVault charges. Read-only: commercial mutations stay on the resource that '.
            'owns them. No payment instrument data is shown here or on any account — no production payment '.
            'gateway is configured, so no stored payment method or payment-method health exists to report.';
    }
}
