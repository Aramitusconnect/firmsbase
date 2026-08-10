<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationRuleResource\Pages;

use App\Filament\Firm\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Services\Automation\AutomationRuleService;
use App\Services\TenantContextService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EditAutomationRule — routes through AutomationRuleService::update(),
 * mirroring CreateAutomationRule. No DeleteAction: rules are disabled
 * (the enabled toggle), never deleted, so their execution history
 * always resolves back to a real rule row — matches
 * PendingPaymentAllocationResource's own "no destructive action" list
 * precedent for a governed, audit-trailed resource.
 */
class EditAutomationRule extends EditRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $data, $firmUser): AutomationRule {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);

                return app(AutomationRuleService::class)->update(
                    firm: $firm,
                    rule: $record,
                    updatedBy: $firmUser,
                    name: $data['name'] ?? null,
                    description: $data['description'] ?? null,
                    conditions: $data['conditions'] ?? null,
                    actions: $data['actions'] ?? null,
                    requiresApproval: array_key_exists('requires_approval', $data) ? (bool) $data['requires_approval'] : null,
                    priority: isset($data['priority']) ? (int) $data['priority'] : null,
                );
            },
        );
    }
}
