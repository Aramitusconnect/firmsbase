<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (Document Center Completion), section 3.4 — adds the
 * client-visibility flag the Client Portal's own document list (Mission
 * 4, a different mission/agent) depends on. Additive only, default
 * false (a document is never client-visible just by existing — an
 * explicit share action, see DocumentSecurityService::setClientVisibility(),
 * must flip it). This column alone does not grant Client Portal access —
 * DocumentSecurityService::canBeViewedInPortalBy() also requires a real
 * ClientPortalMatterAccessPolicyService grant on the document's matter,
 * mirroring the same "no field alone is the boundary" rule
 * canBeDownloadedBy() already established for firm-side access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('client_visible')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('client_visible');
        });
    }
};
