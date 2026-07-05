<?php

namespace App\Enums;

enum ImportSourceType: string
{
    case CsvUpload = 'csv_upload';
    case SpreadsheetUpload = 'spreadsheet_upload';
    case FolderUpload = 'folder_upload';
    case MigrationProject = 'migration_project';
}
