<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicySettingResource\Pages;

use App\Filament\Resources\AiPolicySettingResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListAiPolicySettings — no header actions and no CreateAction: new
 * keys are created implicitly the first time EditAiPolicySettingValueAction
 * (via AiPolicySettingService::set()'s upsert) is used against a key
 * that does not exist yet, mirroring every other List+View-only
 * resource's "no generic Create/Edit forms" convention in this
 * codebase.
 */
class ListAiPolicySettings extends ListRecords
{
    protected static string $resource = AiPolicySettingResource::class;
}
