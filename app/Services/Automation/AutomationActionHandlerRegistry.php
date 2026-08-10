<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Services\Automation\Actions\CreateTaskActionHandler;
use App\Services\Automation\Actions\EscalateDeadlineActionHandler;
use App\Services\Automation\Actions\MarkDocumentRequestItemSubmittedActionHandler;
use App\Services\Automation\Actions\NotifyBillingStaffActionHandler;
use App\Services\Automation\Actions\NotifyResponsibleAttorneyActionHandler;
use App\Services\Automation\Contracts\AutomationActionHandler;

/**
 * AutomationActionHandlerRegistry — Event-Driven Automation Engine,
 * item 6. The ONE place an AutomationActionType string resolves to a
 * handler instance. A fixed, hardcoded array — never a class name
 * pulled from the database, never reflection over an arbitrary string.
 * resolve() throws for anything not a real enum case; this is the
 * structural half of the item 19 firewall (no Trust/Accounting-mutating
 * action type is EVER a key in this map, so no rule can ever be
 * configured to attempt one — the Firm UI's own action-type select is
 * populated from this exact registry).
 */
final class AutomationActionHandlerRegistry
{
    private const MAP = [
        AutomationActionType::CreateTask->value => CreateTaskActionHandler::class,
        AutomationActionType::NotifyBillingStaff->value => NotifyBillingStaffActionHandler::class,
        AutomationActionType::NotifyResponsibleAttorney->value => NotifyResponsibleAttorneyActionHandler::class,
        AutomationActionType::EscalateDeadline->value => EscalateDeadlineActionHandler::class,
        AutomationActionType::MarkDocumentRequestItemSubmitted->value => MarkDocumentRequestItemSubmittedActionHandler::class,
    ];

    public function resolve(AutomationActionType $type): AutomationActionHandler
    {
        $class = self::MAP[$type->value] ?? null;

        if ($class === null) {
            throw new \RuntimeException("No AutomationActionHandler is registered for action type [{$type->value}].");
        }

        return app($class);
    }

    /**
     * @return array<int, string> every registered action type's own
     *                            string value, for Firm UI select
     *                            options and save-time validation
     */
    public function registeredTypes(): array
    {
        return array_keys(self::MAP);
    }
}
