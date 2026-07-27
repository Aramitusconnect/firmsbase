<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListNotificationTemplates — the Create Global Default / Create Firm
 * Override header actions are registered on the Resource's table()
 * itself (see NotificationTemplateResource::table()'s
 * ->headerActions()), not here.
 */
class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;
}
