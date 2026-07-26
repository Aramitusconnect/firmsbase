<?php

namespace Database\Factories;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Enums\TenantEncryptionKeyStatus;
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

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_approval_requests row and its nested ai_usage_event/
     * encryption_key are always tied to the SAME firm.
     *
     * Audit fix (eager-factory-side-effects audit): definition() used
     * to call Firm::factory()->create() as a plain PHP statement at the
     * top of this method, then unconditionally provision a real
     * AiUsageEvent for it via runWithFirmContext() — real, committed
     * side effects every single time, even when forFirm() below
     * immediately overrides firm_id/ai_usage_event_id/encryption_key_id
     * with a caller-supplied firm. Laravel cannot skip a side effect
     * that already happened while building the array. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form and deriving
     * ai_usage_event_id/encryption_key_id from the already-resolved
     * firm_id via lazy closures (mirrors IntegrationOAuthStateFactory's
     * Firm::query()->findOrFail($attributes['firm_id']) convention) —
     * nothing is created unless it survives, unoverridden, to the final
     * row. ai_usage_event_id still requires the real Firm model (not
     * just its id) to route through runWithFirmContext()/forFirm(),
     * since ai_usage_events also has FORCE ROW LEVEL SECURITY with no
     * context-hold create() override of its own.
     *
     * Audit fix (eager-factory-side-effects audit, second pass):
     * fixing AiApprovalEventFactory to correctly route through THIS
     * factory's forFirm($firm) for a caller-supplied real firm (rather
     * than always resolving against a wasted, guaranteed-key-less
     * random firm — see AiApprovalEventFactory's own second-pass fix)
     * exposed a real, previously-hidden bug here: encryption_key_id was
     * unconditionally provisioned via TenantEncryptionKey::factory()
     * ->create(), which violates
     * tenant_encryption_keys_firm_id_key_version_unique
     * (tenant_encryption_keys_one_active_per_firm) the moment it runs
     * against a firm that already has an active key — e.g.
     * SetsUpAiEntitledFirm::makeAiEntitledFirm() already calls
     * EncryptionKeyService::provision($firm) before any
     * AiApprovalRequest/AiApprovalEvent factory ever touches that firm.
     * Fixed by resolveEncryptionKeyIdForFirm() below: only provisions a
     * new key when the firm has no active one yet, mirroring
     * EncryptionKeyService::provision()'s own "at most one active key
     * per firm" production invariant instead of fighting it — the
     * exact same idempotency fix already applied to
     * IntegrationCredentialFactory::encryptFixtureSecret()/
     * IntegrationOAuthStateFactory::encryptFixtureVerifier().
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'requested_by' => User::factory(),
            'ai_usage_event_id' => function (array $attributes) {
                $firm = Firm::query()->findOrFail($attributes['firm_id']);

                return (new TenantContextService)->runWithFirmContext(
                    $firm,
                    fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
                )->id;
            },
            'category' => AiApprovalCategory::LegalResearchMemo,
            'status' => AiApprovalRequestStatus::Pending,
            'draft_label' => 'ai_generated_draft',
            'encrypted_snapshot_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => fn (array $attributes) => $this->resolveEncryptionKeyIdForFirm(
                Firm::query()->findOrFail($attributes['firm_id'])
            ),
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
            'ai_usage_event_id' => (new TenantContextService)->runWithFirmContext(
                $firm,
                fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
            )->id,
            'encryption_key_id' => $this->resolveEncryptionKeyIdForFirm($firm),
        ]);
    }

    /**
     * Returns the firm's existing active TenantEncryptionKey id if one
     * already exists, otherwise provisions a new one — never
     * unconditionally creates. Explicitly wraps the read-then-maybe-
     * write in its own runWithFirmContext($firm, ...) rather than
     * relying on whatever ambient database session context happens to
     * be active at this point in attribute resolution (unreliable —
     * unlike IntegrationCredentialFactory's analogous check, nothing
     * upstream in THIS factory's own definition()/forFirm() leaves
     * $firm's context set as a deliberate side effect, so a bare,
     * unwrapped SELECT could miss a real active key under FORCE RLS and
     * attempt a colliding INSERT).
     */
    private function resolveEncryptionKeyIdForFirm(Firm $firm): int
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $existing = TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->where('status', TenantEncryptionKeyStatus::Active)
                ->first();

            return $existing?->id ?? TenantEncryptionKey::factory()->forFirm($firm)->create()->id;
        });
    }
}
