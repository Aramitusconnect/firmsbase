<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\DeletionRequestStatus;
use App\Enums\RetentionRecordType;
use App\Models\Client;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\DeletionGovernanceService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * SubmitDeletionRequestForApprovalAction — DeletionRequestResource's row
 * action. NOT one of the four DeletionApprovalService methods this
 * phase's own scope named explicitly, but added deliberately: without
 * it, a DeletionRequest can never leave its initial `Requested` status,
 * and DeletionApprovalService::requestApproval() (unlike this method)
 * performs NO clearance check of its own — it unconditionally moves the
 * request straight to PendingApproval regardless of whether the
 * required export/retention/legal-hold gates have actually cleared (see
 * DeletionApprovalService::requestApproval()'s own body: it never calls
 * checkClearance()). Skipping this step in the UI would let an admin
 * request approval for a deletion that was never actually cleared,
 * defeating the entire point of DeletionGovernanceService's three-gate
 * check. This action is the safe, already-tested, already-wired
 * DeletionGovernanceService::submitForApproval() call (see
 * DeletionGovernanceLifecycleTest, which uses exactly this
 * submitForApproval() -> requestApproval() sequence) — genuinely real
 * backend code, not new logic invented for this UI.
 *
 * RetentionRecordType is inferred from `subject_type` via a small,
 * explicit, closed mapping (Matter/Client/Firm/FirmLead — the record
 * types this codebase's DeletionRequest factory/call sites actually
 * target today). subject_type is a free-form string by design
 * (DeletionRequest's own docblock: "deletion governance may target many
 * record types over time"), so an unmapped subject_type is a genuine,
 * disclosed limitation — the action is hidden rather than guessing.
 */
class SubmitDeletionRequestForApprovalAction extends Action
{
    private const SUBJECT_TYPE_TO_RECORD_TYPE = [
        Matter::class => RetentionRecordType::Matter,
        Client::class => RetentionRecordType::Client,
        Firm::class => RetentionRecordType::Firm,
        FirmLead::class => RetentionRecordType::Lead,
    ];

    public static function getDefaultName(): ?string
    {
        return 'submitDeletionRequestForApproval';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Check Clearance & Submit');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('warning');

        $this->requiresConfirmation();
        $this->modalHeading('Check Clearance & Submit for Approval');
        $this->modalDescription('Re-checks the export/retention/legal-hold clearance gates and, if all clear, moves this request to Pending Approval.');

        $this->visible(function (array $record): bool {
            if (! in_array($record['status'] ?? null, [
                DeletionRequestStatus::Requested->value,
                DeletionRequestStatus::ExportClearancePending->value,
                DeletionRequestStatus::RetentionClearancePending->value,
                DeletionRequestStatus::LegalHoldBlocked->value,
            ], true)) {
                return false;
            }

            return array_key_exists($record['subject_type'] ?? '', self::SUBJECT_TYPE_TO_RECORD_TYPE);
        });

        $this->action(function (array $record, PlatformStaffAccessPolicyService $accessPolicy, DeletionGovernanceService $governanceService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageDeletionGovernance($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $recordType = self::SUBJECT_TYPE_TO_RECORD_TYPE[$record['subject_type'] ?? ''] ?? null;

            if ($recordType === null) {
                Notification::make()->title('Unsupported subject type for an automatic clearance check.')->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) $record['firm_uuid']);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $request = (new TenantContextService)->runWithFirmContext($firm, fn () => DeletionRequest::query()->find($record['id']));

            if ($request === null) {
                Notification::make()->title('That deletion request could not be found.')->danger()->send();

                return;
            }

            try {
                $submitted = $governanceService->submitForApproval($request, $recordType);
            } catch (RuntimeException $e) {
                Notification::make()->title('Not yet clear for approval')->body($e->getMessage())->warning()->send();

                return;
            }

            Notification::make()
                ->title('Clearance confirmed')
                ->body("Status: {$submitted->status->value}.")
                ->success()
                ->send();
        });
    }
}
