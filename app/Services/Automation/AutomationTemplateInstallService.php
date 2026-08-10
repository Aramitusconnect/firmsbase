<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;

/**
 * AutomationTemplateInstallService — Event-Driven Automation Engine,
 * item 13/16. Turns an AutomationTemplateCatalog entry into a normal,
 * firm-owned AutomationRule via AutomationRuleService::create() (never
 * a direct AutomationRule::create() — every save-time validation still
 * applies to a template exactly as it would to a hand-built rule).
 * Never auto-installed for a firm without an explicit user action —
 * see this class's own docblock precedent set by every other opt-in
 * capability in this codebase (webhooks, integrations): a firm must
 * ask for automation, not be silently opted into it.
 */
class AutomationTemplateInstallService
{
    public function __construct(private readonly AutomationRuleService $rules) {}

    public function install(Firm $firm, FirmUser $installedBy, string $templateKey): AutomationRule
    {
        $template = AutomationTemplateCatalog::get($templateKey);

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown automation template [{$templateKey}].");
        }

        return $this->rules->create(
            firm: $firm,
            createdBy: $installedBy,
            name: $template['name'],
            description: $template['description'],
            eventType: $template['event_type'],
            conditions: $template['conditions'],
            actions: $template['actions'],
            isStarterTemplate: true,
        );
    }

    /**
     * @return array<int, AutomationRule>
     */
    public function installAll(Firm $firm, FirmUser $installedBy): array
    {
        return array_map(
            fn (string $key) => $this->install($firm, $installedBy, $key),
            array_keys(AutomationTemplateCatalog::templates()),
        );
    }
}
