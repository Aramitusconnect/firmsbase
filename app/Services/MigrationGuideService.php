<?php

namespace App\Services;

use App\Enums\MigrationSourceType;

/**
 * MigrationGuideService — returns static, source-type-specific guidance
 * text/checklists only. No real external API call is ever made against
 * Clio/MyCase/Docketwise/Dropbox/Google Drive (forbidden items) — this
 * service is pure, deterministic, offline text.
 */
class MigrationGuideService
{
    /**
     * @return array<int, string>
     */
    public function stepsFor(MigrationSourceType $sourceType): array
    {
        return match ($sourceType) {
            MigrationSourceType::Spreadsheets => [
                'Export your data to CSV or XLSX.',
                'Upload the file to an import batch.',
                'Map columns to FirmsBase fields.',
                'Preview and confirm before applying.',
            ],
            MigrationSourceType::FolderUpload => [
                'Prepare a folder of documents to upload.',
                'Upload the folder as a document import batch.',
                'Documents are scanned and validated before acceptance.',
            ],
            MigrationSourceType::ClioExport => [
                'Export your data from Clio using its built-in data export tool.',
                'Upload the exported files to a migration project.',
                'Map Clio fields to FirmsBase fields per entity type.',
            ],
            MigrationSourceType::MyCaseExport => [
                'Export your data from MyCase using its built-in data export tool.',
                'Upload the exported files to a migration project.',
                'Map MyCase fields to FirmsBase fields per entity type.',
            ],
            MigrationSourceType::DocketwiseExport => [
                'Export your data from Docketwise using its built-in data export tool.',
                'Upload the exported files to a migration project.',
                'Map Docketwise fields to FirmsBase fields per entity type.',
            ],
            MigrationSourceType::DropboxFolder => [
                'Download the relevant folder from Dropbox to your computer.',
                'Upload the downloaded folder as a document import batch.',
            ],
            MigrationSourceType::GoogleDriveFolder => [
                'Download the relevant folder from Google Drive to your computer.',
                'Upload the downloaded folder as a document import batch.',
            ],
            MigrationSourceType::LocalFiles => [
                'Select the local files you want to import.',
                'Upload them directly to an import batch.',
            ],
        };
    }
}
