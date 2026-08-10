<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\TaskStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\Task;
use App\Services\MatterBudget\MatterProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterProgressService;
    }

    private function matter(Firm $firm): Matter
    {
        return $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
    }

    public function test_a_matter_with_no_tasks_and_no_document_requests_is_zero_percent(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->compute($matter));

        $this->assertSame(0, $result['completion_percent']);
        $this->assertNull($result['breakdown']['tasks']);
        $this->assertNull($result['breakdown']['document_requirements']);
    }

    public function test_task_only_completion_uses_the_task_ratio_alone(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Completed]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Open]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Cancelled]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->compute($matter));

        // 1 completed / 2 non-cancelled = 50%.
        $this->assertSame(50, $result['completion_percent']);
        $this->assertSame(['completed' => 1, 'total' => 2, 'ratio' => 0.5], $result['breakdown']['tasks']);
        $this->assertNull($result['breakdown']['document_requirements']);
    }

    public function test_document_requirement_completion_counts_approved_and_waived_as_satisfied(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'client_id' => $matter->client_id]);
            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Approved, 'is_required' => true]);
            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Waived, 'is_required' => true]);
            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested, 'is_required' => true]);
            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested, 'is_required' => false]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->compute($matter));

        // 2 satisfied / 3 required = 67% (the non-required item excluded entirely).
        $this->assertSame(67, $result['completion_percent']);
        $this->assertSame(['completed' => 2, 'total' => 3, 'ratio' => 2 / 3], $result['breakdown']['document_requirements']);
    }

    public function test_both_components_are_equally_weighted(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Completed]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Open]);

            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'client_id' => $matter->client_id]);
            DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested, 'is_required' => true]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->compute($matter));

        // tasks 50% + documents 0% averaged = 25%.
        $this->assertSame(25, $result['completion_percent']);
    }

    public function test_only_this_matters_tasks_and_documents_are_counted(): void
    {
        $firm = Firm::factory()->create();
        $matterA = $this->matter($firm);
        $matterB = $this->matter($firm);

        $this->runWithFirmContext($firm, function () use ($firm, $matterA, $matterB) {
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterA->id, 'status' => TaskStatus::Completed]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matterB->id, 'status' => TaskStatus::Open]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->compute($matterA));

        $this->assertSame(100, $result['completion_percent']);
    }
}
