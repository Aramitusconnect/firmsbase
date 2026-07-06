<?php

namespace Database\Factories;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Models\AiApprovalRequest;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'requested_by' => User::factory(),
            'ai_usage_event_id' => AiUsageEvent::factory(),
            'category' => AiApprovalCategory::LegalResearchMemo,
            'status' => AiApprovalRequestStatus::Pending,
            'draft_label' => 'ai_generated_draft',
            'encrypted_snapshot_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => TenantEncryptionKey::factory(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
