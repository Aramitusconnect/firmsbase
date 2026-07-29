<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 7 (authorization review, item 19) addition to
 * `financial_evidence_matter_requests`: closes a real IDOR found
 * against `PlaidExchangeController::exchange()`. That controller
 * validated the client-supplied `matter_id` (via
 * `ClientPortalMatterAccessPolicyService::canAccessMatter()`, correct)
 * but resolved the client-supplied `firm_integration_id` by
 * `firm_id` membership ONLY — "belongs to the same firm," never
 * "was created for this matter's own request." `firm_integrations`
 * (2026_09_02_020001) has no matter linkage at all, so nothing anywhere
 * bound a specific connection to a specific matter/request before
 * consent — an authenticated client with legitimate access to *any*
 * matter in a firm could submit their own `public_token` together with
 * a *different* matter's `firm_integration_id` (small, enumerable
 * sequential integers) and complete/activate that other matter's
 * connection with their own bank credential, attaching
 * attacker-controlled financial data to a different client's evidence
 * record.
 *
 * Fix: this new nullable `firm_integration_id` column is the
 * server-authoritative binding, set once by
 * `PlaidAccountSelectionPage::mount()` at the exact moment it calls
 * `ProviderConnectionService::startConnection()` to create the
 * connection FOR this specific pending request — the earliest possible
 * point the binding is known, before the client ever sees a
 * `firm_integration_id` at all. `PlaidExchangeController::exchange()`
 * now resolves the connection from THIS column, never from
 * client-supplied input.
 *
 * Nullable and `nullOnDelete()`: every row created before this
 * migration (and any row whose request-review flow never reached the
 * account-selection step) legitimately has no connection yet — this is
 * a "not yet known" state, never an error condition, mirroring this
 * table's own `cancelled_at` nullable-timestamp precedent for an
 * optional, later-populated fact. `nullOnDelete()` (not
 * `cascadeOnDelete()`): deleting a `firm_integrations` row must never
 * cascade-delete the firm's own outbound request record — the request
 * itself remains a legitimate audit trail entry independent of whether
 * the connection it produced still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_evidence_matter_requests', function (Blueprint $table) {
            $table->foreignId('firm_integration_id')
                ->nullable()
                ->after('matter_id')
                ->constrained('firm_integrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_evidence_matter_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('firm_integration_id');
        });
    }
};
