<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterBudgetTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetTemplateService(new MatterBudgetAccessPolicyService);
    }

    private function owner(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
    }

    public function test_authorized_role_can_create_a_template(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $template = $this->service->create(
            $firm, $owner, 'Immigration AOS', null, null, null,
            ['attorney' => 8, 'paralegal' => 15], ['filing_court_costs' => 20000],
        );

        $this->assertInstanceOf(MatterBudgetTemplate::class, $template);
        $this->assertSame(1, $template->version);
        $this->assertTrue($template->active);
    }

    public function test_unauthorized_role_cannot_create_a_template(): void
    {
        $firm = Firm::factory()->create();
        $receptionist = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Receptionist]));

        $this->expectException(\RuntimeException::class);

        $this->service->create($firm, $receptionist, 'x', null, null, null, [], []);
    }

    public function test_unknown_role_key_in_expected_hours_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($firm, $owner, 'x', null, null, null, ['ceo' => 5], []);
    }

    public function test_unknown_category_key_in_expected_expenses_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($firm, $owner, 'x', null, null, null, [], ['bribes' => 500]);
    }

    public function test_high_threshold_below_warning_threshold_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($firm, $owner, 'x', null, null, null, [], [], warningThresholdPercent: 90, highThresholdPercent: 75);
    }

    public function test_editing_hours_bumps_the_version(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $template = $this->service->create($firm, $owner, 'x', null, null, null, ['attorney' => 8], []);

        $updated = $this->service->update($firm, $template, $owner, expectedHours: ['attorney' => 10]);

        $this->assertSame(2, $updated->version);
    }

    public function test_editing_only_the_name_does_not_bump_the_version(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $template = $this->service->create($firm, $owner, 'x', null, null, null, ['attorney' => 8], []);

        $updated = $this->service->update($firm, $template, $owner, name: 'Renamed');

        $this->assertSame(1, $updated->version);
        $this->assertSame('Renamed', $updated->name);
    }

    public function test_set_active_toggles_the_template(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $template = $this->service->create($firm, $owner, 'x', null, null, null, [], []);

        $updated = $this->service->setActive($firm, $template, false, $owner);

        $this->assertFalse($updated->active);
    }

    public function test_duplicate_creates_a_new_template_with_the_same_fields(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $template = $this->service->create($firm, $owner, 'Original', null, null, null, ['attorney' => 8], ['filing_court_costs' => 100]);

        $duplicate = $this->service->duplicate($firm, $template, $owner, 'Copy of Original');

        $this->assertNotSame($template->id, $duplicate->id);
        $this->assertSame('Copy of Original', $duplicate->name);
        $this->assertSame(['attorney' => 8], $duplicate->expected_hours_json);
    }

    public function test_cross_firm_update_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerA = $this->owner($firmA);
        $ownerB = $this->owner($firmB);
        $templateA = $this->service->create($firmA, $ownerA, 'x', null, null, null, [], []);

        $this->expectException(\RuntimeException::class);

        $this->service->update($firmB, $templateA, $ownerB, name: 'Hijacked');
    }
}
