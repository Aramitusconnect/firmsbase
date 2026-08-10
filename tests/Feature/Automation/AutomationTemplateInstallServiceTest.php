<?php

namespace Tests\Feature\Automation;

use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Automation\AutomationAccessPolicyService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\AutomationTemplateCatalog;
use App\Services\Automation\AutomationTemplateInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationTemplateInstallServiceTest — Event-Driven Automation
 * Engine, item 13/16. Proves every starter template installs as a
 * completely normal, inspectable, disableable firm-owned rule routed
 * through AutomationRuleService's own save-time validation — never a
 * hardcoded hidden workflow — and that installing is itself subject to
 * the same authorization gate as hand-building a rule.
 */
class AutomationTemplateInstallServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutomationTemplateInstallService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomationTemplateInstallService(
            new AutomationRuleService(new AutomationActionHandlerRegistry, new AutomationAccessPolicyService),
        );
    }

    public function test_installing_a_known_template_creates_a_normal_enabled_rule(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $rule = $this->service->install($firm, $owner, 'invoice_overdue_reminder');

        $this->assertInstanceOf(AutomationRule::class, $rule);
        $this->assertTrue($rule->is_starter_template);
        $this->assertTrue($rule->enabled);
        $this->assertSame(DomainEventType::InvoiceOverdue, $rule->event_type);
    }

    public function test_installing_an_unknown_template_throws(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->install($firm, $owner, 'does_not_exist');
    }

    public function test_installing_a_template_is_gated_by_the_same_authorization_as_hand_building_a_rule(): void
    {
        $firm = Firm::factory()->create();
        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]));

        $this->expectException(\RuntimeException::class);

        $this->service->install($firm, $paralegal, 'invoice_overdue_reminder');
    }

    public function test_install_all_creates_one_rule_per_catalog_entry_with_no_trust_or_accounting_action(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $rules = $this->service->installAll($firm, $owner);

        $this->assertCount(count(AutomationTemplateCatalog::templates()), $rules);

        foreach ($rules as $rule) {
            foreach ($rule->actions_json as $actionDef) {
                $this->assertNotSame('create_trust_ledger_entry', $actionDef['action_type']);
                $this->assertNotSame('create_accounting_journal_entry', $actionDef['action_type']);
            }
        }
    }
}
