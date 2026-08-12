<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 7 —
 * "document quarantine integration for intake uploads." Rather than
 * inventing a second quarantine/scan pipeline for intake-time uploads,
 * this reuses the existing, mature `documents` table and its single
 * canonical writer (DocumentSecurityService) wholesale — the same
 * ScanDocumentJob, the same VirusScanner abstraction, the same
 * DocumentUploadPolicyService extension/size rules, the same
 * Document::isUsable() gate every consumer must already respect.
 *
 * `marketplace_intake_id` is nullable and additive, mirroring
 * `matter_id`/`client_id`/`document_request_item_id`'s own existing
 * optional-context-FK shape on this table — a document uploaded during
 * a MyAttorney intake has NO Matter/Client yet (that conversion is a
 * later checkpoint, #11) but does already have a real, non-null owning
 * Firm (MarketplaceIntake.firm_id is NOT NULL from creation), so no
 * nullable-firm_id RLS redesign is needed here — the existing
 * documents_tenant_isolation policy already covers this case as-is
 * once the write happens inside the intake's own firm context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('marketplace_intake_id')->nullable()->after('document_request_item_id')->constrained('marketplace_intakes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketplace_intake_id');
        });
    }
};
