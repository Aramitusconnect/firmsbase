<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * automation_rules — Event-Driven Automation Engine, item 4. Firm-owned,
 * fully inspectable/editable/disableable rules — never a hidden hardcoded
 * workflow, including the six first-party starter templates
 * (is_starter_template just labels provenance in the UI).
 *
 * conditions_json is an array of {field, operator, value} clauses,
 * ALL of which must pass (AND semantics only — no nested OR groups in
 * this pass; a deliberate scope limit, not an oversight, matching "no
 * user-supplied code, no unrestricted expressions"). `field` values are
 * validated against ConditionEvaluatorService's own per-event-type
 * allowlist at save time AND re-validated at evaluation time — never
 * trusted as free text. `operator` is validated against the closed
 * ConditionOperator enum.
 *
 * actions_json is an ordered array of {action_type, config}. action_type
 * is validated against the closed ActionType registry at save time; each
 * action's config is validated against that ActionType's own schema.
 * Storing config here is data, never executable code — no eval, no
 * arbitrary method/class names, no reflection.
 *
 * requires_approval is a FIRM-settable field, but it can never legally
 * be false when the rule contains an action whose ActionType is itself
 * classified REQUIRES_APPROVAL or PROHIBITED in the hardcoded registry
 * (AutomationRuleService validates this at save time) — the real safety
 * gate at EXECUTION time is always the ActionType registry's own
 * hardcoded risk classification, re-checked independently, never this
 * stored column alone (defense in depth: a firm can only ADD an extra
 * approval requirement here, never remove one the registry demands).
 *
 * version increments on every edit to event_type/conditions_json/
 * actions_json (never on enabled/priority/name/description alone) —
 * AutomationExecution snapshots this value at match time so the audit
 * trail always reflects exactly which rule definition decided an
 * outcome, even if the rule is edited later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('event_type');
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0);

            $table->json('conditions_json');
            $table->json('actions_json');

            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_starter_template')->default(false);
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('updated_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'event_type', 'enabled']);
            $table->index(['firm_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
