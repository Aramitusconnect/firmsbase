<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_review_checklist_items — approved optional table. Chosen
 * tenant-scoping approach (documented per your request): NO firm_id
 * column. It is scoped transitively through form_draft_id — you can
 * never reach a checklist item without first loading its parent
 * FormDraft, which IS BelongsToTenant-scoped and additionally guarded
 * by TenantSafeFormAndDocumentPolicyService. This mirrors
 * form_draft_values/form_missing_data_items exactly, for the same
 * reason: a redundant firm_id copy here could drift from the parent's
 * and adds no real safety over the transitive path.
 *
 * This is also the concrete backing for the WCAG "accessible checklist
 * controls" readiness item (see FormAccessibilityReadinessService) —
 * individually labeled, boolean, addressable rows rather than one
 * opaque flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_review_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_draft_id')->constrained('form_drafts')->cascadeOnDelete();
            $table->string('checklist_code');
            $table->string('label');
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();

            $table->timestamps();

            $table->unique(['form_draft_id', 'checklist_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_review_checklist_items');
    }
};
