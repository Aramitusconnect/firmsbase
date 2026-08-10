<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterBudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetService(new MatterBudgetAccessPolicyService);
    }

    private function owner(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
    }

    private function matter(Firm $firm): Matter
    {
        return $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
    }

    public function test_a_matter_with_no_budget_has_no_current_budget(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);

        $current = $this->runWithFirmContext($firm, fn () => $this->service->current($matter));

        $this->assertNull($current);
    }

    public function test_applying_a_template_creates_version_one_and_snapshots_the_template(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create([
            'name' => 'Immigration AOS', 'expected_hours_json' => ['attorney' => 8], 'version' => 3,
        ]));

        $budget = $this->service->applyTemplate($firm, $matter, $template, $owner);

        $this->assertSame(1, $budget->version);
        $this->assertSame($template->id, $budget->source_template_id);
        $this->assertSame(3, $budget->source_template_version);
        $this->assertSame(['attorney' => 8], $budget->expected_hours_json);
    }

    public function test_editing_the_template_afterward_never_changes_the_already_applied_snapshot(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create([
            'expected_hours_json' => ['paralegal' => 15],
        ]));

        $budget = $this->service->applyTemplate($firm, $matter, $template, $owner);

        $this->runWithFirmContext($firm, fn () => $template->update(['expected_hours_json' => ['paralegal' => 25]]));

        $reRead = $this->runWithFirmContext($firm, fn () => $budget->fresh()->expected_hours_json);
        $this->assertSame(['paralegal' => 15], $reRead);
    }

    public function test_reapplying_a_template_to_a_matter_that_already_has_a_budget_creates_a_new_version(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create());

        $this->service->applyTemplate($firm, $matter, $template, $owner);
        $second = $this->service->applyTemplate($firm, $matter, $template, $owner);

        $this->assertSame(2, $second->version);
        $this->assertNotNull($second->change_reason);
    }

    public function test_revise_custom_requires_a_change_reason_when_a_budget_already_exists(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $this->service->reviseCustom($firm, $matter, $owner, ['attorney' => 8], []);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->reviseCustom($firm, $matter, $owner, ['attorney' => 12], []);
    }

    public function test_revise_custom_with_a_change_reason_creates_a_new_version_and_preserves_the_old_one(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $first = $this->service->reviseCustom($firm, $matter, $owner, ['paralegal' => 15], []);

        $second = $this->service->reviseCustom(
            $firm, $matter, $owner, ['paralegal' => 22], [], changeReason: 'Scope expanded per client request.',
        );

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $firstReRead = $this->runWithFirmContext($firm, fn () => $first->fresh()->expected_hours_json);
        $this->assertSame(['paralegal' => 15], $firstReRead);
        $this->assertSame(['paralegal' => 22], $second->expected_hours_json);

        $current = $this->runWithFirmContext($firm, fn () => $this->service->current($matter));
        $this->assertSame($second->id, $current->id);
    }

    public function test_the_first_budget_for_a_matter_needs_no_change_reason(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);

        $budget = $this->service->reviseCustom($firm, $matter, $owner, ['attorney' => 8], []);

        $this->assertSame(1, $budget->version);
        $this->assertNull($budget->change_reason);
    }

    public function test_an_existing_matter_budget_row_can_never_be_updated_in_place(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $matter = $this->matter($firm);
        $budget = $this->service->reviseCustom($firm, $matter, $owner, ['attorney' => 8], []);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $budget->update(['expected_duration_days' => 99]));
    }

    public function test_unauthorized_role_cannot_apply_a_template(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matter($firm);
        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]));
        $template = $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create());

        $this->expectException(\RuntimeException::class);

        $this->service->applyTemplate($firm, $matter, $template, $paralegal);
    }

    public function test_cross_firm_apply_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerA = $this->owner($firmA);
        $matterA = $this->matter($firmA);
        $templateB = $this->runWithFirmContext($firmB, fn () => MatterBudgetTemplate::factory()->forFirm($firmB)->create());

        $this->expectException(\RuntimeException::class);

        $this->service->applyTemplate($firmA, $matterA, $templateB, $ownerA);
    }
}
