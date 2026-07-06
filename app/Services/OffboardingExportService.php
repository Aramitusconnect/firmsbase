<?php

namespace App\Services;

use App\Enums\ExportType;
use App\Enums\OffboardingExportStatus;
use App\Models\FirmUser;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;

/**
 * OffboardingExportService — a governed WRAPPER around the EXISTING
 * Phase 8 ExportJobService/ExportJob/ExportFile simulated-export
 * foundation (project rule: do not build a second export engine).
 * Reuses the EXISTING ExportType::OffboardingPackage case. No real ZIP
 * or storage write ever happens — package_manifest_json is a declared
 * list of data-category strings.
 */
class OffboardingExportService
{
    private const DEFAULT_MANIFEST = [
        'clients',
        'matters',
        'documents',
        'invoices',
        'trust_ledger_entries_summary',
        'timeline_events',
    ];

    public function __construct(
        private readonly ExportJobService $exportJobService,
    ) {
    }

    public function generate(
        OffboardingRequest $request,
        ?FirmUser $requestedByFirmUser = null,
        ?PlatformAdmin $requestedByPlatformAdmin = null,
        array $manifest = self::DEFAULT_MANIFEST,
    ): OffboardingExport {
        $exportJob = $this->exportJobService->request(
            $request->firm,
            ExportType::OffboardingPackage,
            $requestedByFirmUser,
            $requestedByPlatformAdmin,
            'Offboarding export for offboarding_request '.$request->id,
        );

        return OffboardingExport::create([
            'offboarding_request_id' => $request->id,
            'export_job_id' => $exportJob->id,
            'status' => OffboardingExportStatus::Generated,
            'package_manifest_json' => $manifest,
            'generated_at' => now(),
        ]);
    }

    public function verify(OffboardingExport $export, PlatformAdmin $verifiedBy): OffboardingExport
    {
        if (empty($export->package_manifest_json)) {
            throw new \RuntimeException('Cannot verify an offboarding export with an empty package manifest.');
        }

        $export->update([
            'status' => OffboardingExportStatus::Verified,
            'verified_at' => now(),
            'verified_by_platform_admin_id' => $verifiedBy->id,
        ]);

        return $export->fresh();
    }
}
