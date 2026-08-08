<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages;

use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListDocumentChaseRules — getSubheading() carries this module's
 * honest-capability copy on every page load (not just a one-off form
 * helper text), so a Firm Owner scanning the list never mistakes a rule
 * for a live reminder pipeline.
 */
class ListDocumentChaseRules extends ListRecords
{
    protected static string $resource = DocumentChaseRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Chase rules define reminder eligibility only. Automatic reminder sending is not yet enabled — no email, SMS, or other message is actually sent to any client.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ New Chase Rule'),
        ];
    }
}
