<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;
use App\Models\AutomationRule;
use App\Models\DocumentRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\Task;
use App\Services\Automation\ConditionEvaluatorService;
use App\Services\Automation\WorkflowPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkflowPreviewServiceTest — Zero-Click Core Workflow Automation,
 * test matrix W. Proves preview is genuinely read-only (no Task/
 * DocumentRequest row is ever created by calling it) and correctly
 * describes what a matched rule's own actions would do.
 */
class WorkflowPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WorkflowPreviewService
    {
        return new WorkflowPreviewService(new ConditionEvaluatorService);
    }

    public function test_preview_never_mutates_any_record(): void
    {
        $firm = Firm::factory()->create();

        [$rule, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'conditions_json' => [],
                'actions_json' => [
                    ['action_type' => AutomationActionType::CreateDocumentRequest->value, 'config' => ['title' => 'X', 'items' => [['label' => 'ID']]]],
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Y', 'assigned_to' => 'matter_assigned_attorney']],
                ],
            ]);
            $matter = Matter::factory()->forFirm($firm)->create();

            return [$rule, $matter];
        });

        $taskCountBefore = $this->runWithFirmContext($firm, fn () => Task::query()->count());
        $requestCountBefore = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->count());

        $preview = $this->runWithFirmContext($firm, fn () => $this->service()->previewForMatter($rule, $matter));

        $taskCountAfter = $this->runWithFirmContext($firm, fn () => Task::query()->count());
        $requestCountAfter = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->count());

        $this->assertSame($taskCountBefore, $taskCountAfter);
        $this->assertSame($requestCountBefore, $requestCountAfter);
        $this->assertTrue($preview['would_match']);
        $this->assertCount(2, $preview['actions']);
    }

    public function test_preview_reports_when_conditions_would_not_match(): void
    {
        $firm = Firm::factory()->create();

        [$rule, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'conditions_json' => [
                    ['field' => 'matter.matter_type_id', 'operator' => AutomationConditionOperator::Equals->value, 'value' => 999999],
                ],
                'actions_json' => [
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Y', 'assigned_to' => 'matter_assigned_attorney']],
                ],
            ]);
            $matter = Matter::factory()->forFirm($firm)->create();

            return [$rule, $matter];
        });

        $preview = $this->runWithFirmContext($firm, fn () => $this->service()->previewForMatter($rule, $matter));

        $this->assertFalse($preview['would_match']);
        $this->assertNotNull($preview['blocked_reason']);
        $this->assertEmpty($preview['actions']);
    }

    public function test_preview_reports_a_disabled_rule_as_not_matching(): void
    {
        $firm = Firm::factory()->create();

        [$rule, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->disabled()->create([
                'conditions_json' => [],
            ]);
            $matter = Matter::factory()->forFirm($firm)->create();

            return [$rule, $matter];
        });

        $preview = $this->runWithFirmContext($firm, fn () => $this->service()->previewForMatter($rule, $matter));

        $this->assertFalse($preview['would_match']);
    }

    public function test_preview_is_unsupported_for_a_non_matter_opened_rule(): void
    {
        $firm = Firm::factory()->create();

        [$rule, $matter] = $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::InvoiceOverdue)->create();
            $matter = Matter::factory()->forFirm($firm)->create();

            return [$rule, $matter];
        });

        $preview = $this->runWithFirmContext($firm, fn () => $this->service()->previewForMatter($rule, $matter));

        $this->assertFalse($preview['previewable']);
        $this->assertFalse($preview['would_match']);
    }
}
