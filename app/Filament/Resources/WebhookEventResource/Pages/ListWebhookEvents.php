<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEventResource\Pages;

use App\Filament\Resources\WebhookEventResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListWebhookEvents — no header actions: cross-firm oversight view only,
 * no mutating action of any kind (see WebhookEventResource's own
 * docblock).
 */
class ListWebhookEvents extends ListRecords
{
    protected static string $resource = WebhookEventResource::class;
}
