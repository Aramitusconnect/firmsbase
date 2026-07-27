<?php

namespace Tests\Feature\Governance\Offboarding;

use App\Enums\LegalHoldScope;
use App\Enums\OffboardingRequestStatus;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\RetentionPolicy;
use App\Models\SecurityEvent;
use App\Services\LegalHoldService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class OffboardingRequestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    public function test_request_starts_in_requested_status(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();

        $request = app(OffboardingRequestService::class)->request($firm, $admin, 'Firm cancelled.');

        $this->assertSame(OffboardingRequestStatus::Requested, $request->status);
    }

    public function test_advance_reaches_ready_for_deletion_once_export_retention_and_legal_hold_all_clear(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);
        $exportService = app(OffboardingExportService::class);

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');
        $export = $exportService->generate($request, requestedByPlatformAdmin: $admin);
        $exportService->verify($export, $admin);

        // Force firm.created_at far enough in the past for retention to clear.
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::ReadyForDeletion, $advanced->status);
    }

    public function test_advance_reports_legal_hold_blocked_when_an_active_firm_hold_exists(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);
        $exportService = app(OffboardingExportService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');
        $export = $exportService->generate($request, requestedByPlatformAdmin: $admin);
        $exportService->verify($export, $admin);

        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation pending.', $admin);

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::LegalHoldBlocked, $advanced->status);
    }

    public function test_advance_reports_export_pending_when_no_verified_export_exists(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $advanced = $requestService->advance($request);

        $this->assertSame(OffboardingRequestStatus::ExportPending, $advanced->status);
    }

    // ------------------------------------------------------------
    // FVACC mission-wide final hardening review finding — actor +
    // audit plumbing on advance()/complete()/cancel().
    // ------------------------------------------------------------

    public function test_advance_without_an_actor_writes_no_audit_event_and_behaves_exactly_as_before(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $requestService->advance($request);

        $count = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'offboarding_request.advanced')
            ->count());
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_advance_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $requestService->advance($request, actor: $admin);

        $row = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'offboarding_request.advanced')
            ->first());

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
    }

    public function test_complete_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);
        $exportService = app(OffboardingExportService::class);

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');
        $export = $exportService->generate($request, requestedByPlatformAdmin: $admin);
        $exportService->verify($export, $admin);
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();
        $advanced = $requestService->advance($request);

        $completed = $requestService->complete($advanced, actor: $admin);

        $this->assertSame(OffboardingRequestStatus::Completed, $completed->status);

        $row = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'offboarding_request.completed')
            ->first());

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
    }

    public function test_cancel_without_an_actor_writes_no_audit_event_and_behaves_exactly_as_before(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $cancelled = $requestService->cancel($request, 'No longer needed.');

        $this->assertSame(OffboardingRequestStatus::Cancelled, $cancelled->status);

        $count = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'offboarding_request.cancelled')
            ->count());
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_cancel_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $requestService = app(OffboardingRequestService::class);

        $request = $requestService->request($firm, $admin, 'Firm cancelled.');

        $requestService->cancel($request, 'No longer needed.', actor: $admin);

        $row = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'offboarding_request.cancelled')
            ->first());

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
    }
}
