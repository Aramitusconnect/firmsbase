<?php

namespace App\Enums;

/**
 * MigrationSourceType — source types are guides/labels only (project
 * rule). No real external API call is ever made against Clio/MyCase/
 * Docketwise/Dropbox/Google Drive for any of these cases.
 */
enum MigrationSourceType: string
{
    case Spreadsheets = 'spreadsheets';
    case FolderUpload = 'folder_upload';
    case ClioExport = 'clio_export';
    case MyCaseExport = 'mycase_export';
    case DocketwiseExport = 'docketwise_export';
    case DropboxFolder = 'dropbox_folder';
    case GoogleDriveFolder = 'google_drive_folder';
    case LocalFiles = 'local_files';
}
