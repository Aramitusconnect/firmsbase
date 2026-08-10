<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationRuleResource\Pages;

use App\Enums\DomainEventType;
use App\Filament\Firm\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Services\Automation\AutomationRuleService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CreateAutomationRule — routes through AutomationRuleService::create()
 * rather than a bare Eloquent save, mirroring CreateTask's own
 * established precedent for this exact reason: every save-time
 * validation (field allowlist, closed operator/action-type vocabulary,
 * requires_approval can never be forced off) must apply to every rule
 * a firm user builds by hand, not only to the first-party templates.
 */
class CreateAutomationRule extends CreateRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): AutomationRule {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);

                return app(AutomationRuleService::class)->create(
                    firm: $firm,
                    createdBy: $firmUser,
                    name: $data['name'],
                    description: $data['description'] ?? null,
                    eventType: DomainEventType::from($data['event_type']),
                    conditions: $data['conditions'] ?? [],
                    actions: $data['actions'] ?? [],
                    requiresApproval: (bool) ($data['requires_approval'] ?? false),
                    enabled: (bool) ($data['enabled'] ?? true),
                    priority: (int) ($data['priority'] ?? 0),
                );
            },
        );
    }
}
