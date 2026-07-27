<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\OffboardingExportStatus;
use App\Models\Firm;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\OffboardingExportService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkOffboardingExportVerifiedAction — nested action on the offboarding
 * exports table embedded in OffboardingRequestResource's View page.
 * Routes exclusively through
 * OffboardingExportService::verify($export, PlatformAdmin $verifiedBy)
 * — already a genuine, already-correctly-typed PlatformAdmin-attributed
 * mutation (see that service's own docblock), requiring no new backend
 * design.
 *
 * TOCTOU-safe AND ownership-safe: `offboarding_exports` carries NO row
 * level security of its own (disclosed Uncertain classification — see
 * PlatformDataExportGovernanceDirectoryService's own docblock), so
 * fetching it fresh by id alone would not be blocked by anything if the
 * given id belonged to a different firm's export. This action
 * re-establishes ownership explicitly: it re-resolves the parent
 * OffboardingRequest under the target firm's own FORCE-RLS-protected
 * context first, and only proceeds if the export's own
 * offboarding_request_id matches that already-firm-verified parent —
 * mirroring the class docblock's "join through the RLS-covered parent,
 * never query offboarding_exports blind" discipline at the mutation
 * layer, not just the read layer.
 *
 * Never implies a real file exists — the confirmation copy and success
 * notification both describe this as confirming the DECLARED manifest
 * was reviewed, never "downloading" or "verifying a file."
 */
class MarkOffboardingExportVerifiedAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'verifyOffboardingExport';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Verify');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Verify Offboarding Export');
        $this->modalDescription('Confirms the declared data-category manifest for this export has been reviewed. No real file is ever produced or downloaded by this system — package_manifest_json is a declared list of data-category strings only.');

        $this->visible(fn (array $record): bool => ($record['status'] ?? null) === OffboardingExportStatus::Generated->value);

        $this->action(function (array $record, PlatformStaffAccessPolicyService $accessPolicy, OffboardingExportService $offboardingExportService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageDataExports($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $firm = Firm::findByUuid((string) ($record['firm_uuid'] ?? ''));

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            $ownershipVerifiedRequestId = (new TenantContextService)->runWithFirmContext(
                $firm,
                fn () => OffboardingRequest::query()->find($record['offboarding_request_id'])?->id
            );

            if ($ownershipVerifiedRequestId === null) {
                Notification::make()->title('The parent offboarding request could not be found for this firm.')->danger()->send();

                return;
            }

            $export = OffboardingExport::query()->find($record['id']);

            if ($export === null || $export->offboarding_request_id !== $ownershipVerifiedRequestId) {
                Notification::make()->title('That export could not be found for this offboarding request.')->danger()->send();

                return;
            }

            if ($export->status !== OffboardingExportStatus::Generated) {
                Notification::make()->title('This export is not currently pending verification.')->warning()->send();

                return;
            }

            $verified = $offboardingExportService->verify($export, $actor);

            Notification::make()
                ->title('Offboarding export verified')
                ->body("Status: {$verified->status->value}. No real file was produced or downloaded.")
                ->success()
                ->send();
        });
    }
}
