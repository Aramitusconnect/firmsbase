<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\Pages;

use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirmIntegrations — Checkpoint 10 (frozen-design-post-security-
 * review.md §12). No CreateAction is registered (§11.1's Action-based,
 * never Form-backed Create page ruling) — ConnectProviderAction is the
 * sole header action, redirect-initiation only.
 */
class ListFirmIntegrations extends ListRecords
{
    protected static string $resource = FirmIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectProviderAction::make(),
        ];
    }
}
