<?php

namespace Database\Factories;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Models\AiApprovalRequest;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AiApprovalRequest>
 *
 * NOTE: encrypted_snapshot_ciphertext below is a placeholder, not a
 * genuinely decryptable ciphertext (same convention as
 * WebhookSecretFactory/FirmAiProviderKeyFactory) — tests exercising a
 * real encrypt/decrypt round trip must go through
 * AiApprovalWorkflowService::submit() against a firm with an active
 * TenantEncryptionKey.
 */
class AiApprovalRequestFactory extends Factory
{
    protected $model = AiApprovalRequest::class;

    /**
     * ai_approval_requests has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950016_prepare_row_level_security_
     * and_force_rls_on_ai_approval_requests_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterExpenseFactory::create()'s docblock for the
     * full rationale, including why setDatabaseTenantContextForFirmId()
     * is used instead of setFirmContext()/runWithFirmContext() and why
     * the setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_approval_requests row and its nested ai_usage_event/
     * encryption_key are always tied to the SAME firm — one
     * authoritative firm is generated up front (rather than letting
     * firm_id, ai_usage_event_id, and encryption_key_id resolve as
     * three independent Firm::factory() calls), matching the root-cause
     * fix already applied to MatterExpenseFactory/MatterFactory/
     * InvoiceFactory/PaymentFactory. A bare ai_approval_requests row
     * whose usage event or encryption key belongs to an unrelated firm
     * is exactly the transitive cross-firm mismatch documented as a
     * known, deliberately-deferred gap in this table's FORCE migration
     * (no composite FK/trigger enforces it at the database layer) —
     * the factory must not manufacture that invalid shape by default
     * just because RLS itself cannot catch it.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'matter_id' => null,
            'requested_by' => User::factory(),
            // Eagerly created and wrapped in its own tenant context
            // (not passed as a lazy Factory instance) because
            // ai_usage_events also has FORCE ROW LEVEL SECURITY with no
            // context-hold create() override of its own (by design —
            // see AiUsageEventFactory) — Laravel resolves a nested
            // Factory value during make(), before this factory's own
            // create() override establishes context below, so a lazy
            // reference here would insert with no context active.
            'ai_usage_event_id' => (new TenantContextService())->runWithFirmContext(
                $firm,
                fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
            )->id,
            'category' => AiApprovalCategory::LegalResearchMemo,
            'status' => AiApprovalRequestStatus::Pending,
            'draft_label' => 'ai_generated_draft',
            'encrypted_snapshot_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => TenantEncryptionKey::factory()->forFirm($firm),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            // Eagerly created and wrapped in its own tenant context
            // (not passed as a lazy Factory instance) because
            // ai_usage_events also has FORCE ROW LEVEL SECURITY with no
            // context-hold create() override of its own (by design —
            // see AiUsageEventFactory) — Laravel resolves a nested
            // Factory value during make(), before this factory's own
            // create() override establishes context below, so a lazy
            // reference here would insert with no context active.
            'ai_usage_event_id' => (new TenantContextService())->runWithFirmContext(
                $firm,
                fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
            )->id,
            'encryption_key_id' => TenantEncryptionKey::factory()->forFirm($firm),
        ]);
    }
}
