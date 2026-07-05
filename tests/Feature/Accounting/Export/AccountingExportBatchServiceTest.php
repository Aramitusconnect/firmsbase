<?php

namespace Tests\Feature\Accounting\Export;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingExportBatchService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingExportBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingExportBatchService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new AccountingExportBatchService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    /** Required: fake QuickBooks export creates a logged batch. */
    public function test_batch_lifecycle_transitions(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());
        $this->assertSame(AccountingExportBatchStatus::Requested, $batch->status);

        $batch = $this->service->markInProgress($batch);
        $this->assertSame(AccountingExportBatchStatus::InProgress, $batch->status);

        $batch = $this->service->markCompleted($batch);
        $this->assertSame(AccountingExportBatchStatus::Completed, $batch->status);
        $this->assertNotNull($batch->completed_at);
    }

    /** Required: disabled Expenses blocks accounting export. */
    public function test_batch_is_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());

        $this->assertSame(AccountingExportBatchStatus::Blocked, $batch->status);
    }

    public function test_terminal_batch_cannot_be_modified(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());
        $batch = $this->service->markInProgress($batch);
        $batch = $this->service->markCompleted($batch);

        $this->expectException(\RuntimeException::class);
        $this->service->markInProgress($batch);
    }
}
