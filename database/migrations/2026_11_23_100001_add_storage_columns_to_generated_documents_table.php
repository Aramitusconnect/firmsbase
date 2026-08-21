<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: generated_documents gains storage_disk/storage_path
 * now that DocumentGenerationService::generate() performs real Dompdf
 * rendering and writes real bytes via Storage::disk(...). Both columns
 * are nullable — simulated_storage_path (the pre-existing descriptive
 * metadata string) is left untouched and still populated by every
 * caller, since its exact current format is still relied on elsewhere
 * (see DocumentGenerationServiceTest and the RLS activation fixtures).
 * GeneratedDocument deliberately does NOT gain a scan_status column
 * here — it is system-rendered from attorney/platform-admin-approved
 * template content, never user-uploaded bytes, so it correctly stays
 * outside the malware-scan pipeline (ScanDocumentJob remains
 * Document-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->string('storage_disk')->nullable()->after('simulated_storage_path');
            $table->string('storage_path')->nullable()->after('storage_disk');
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'storage_path']);
        });
    }
};
