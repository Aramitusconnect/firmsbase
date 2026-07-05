<?php

namespace Tests\Feature\Trust\Readiness;

use App\Models\Firm;
use App\Services\TrustPilotExitCriteriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #16: pilot exit criteria are reporting-only and must never
 * themselves gate any feature, route, entitlement, or module.
 */
class TrustPilotExitCriteriaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_reports_that_it_gates_nothing_automatically(): void
    {
        $firm = Firm::factory()->create();

        $checklist = app(TrustPilotExitCriteriaService::class)->checklistFor($firm);

        $this->assertFalse($checklist['gates_anything_automatically']);
        $this->assertNotEmpty($checklist['exit_criteria']);
    }
}
