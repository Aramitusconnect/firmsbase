<?php

namespace Database\Factories;

use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 *
 * Defaults to a platform-default policy (firm_id null). Use
 * ->forFirm($firm) for a firm-specific override, ->permanent() for the
 * trust-ledger-style "never clears" posture.
 */
class RetentionPolicyFactory extends Factory
{
    protected $model = RetentionPolicy::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'document_category' => null,
            'practice_area' => null,
            'jurisdiction' => null,
            'retention_period_days' => 2555,
            'is_permanent' => false,
            'allows_client_replacement' => false,
            'preserves_audit_history_required' => true,
            'legal_basis' => null,
            'status' => RetentionPolicyStatus::Active,
            'effective_at' => now(),
            'superseded_at' => null,
            'supersedes_policy_id' => null,
            'reason' => 'Standard retention policy.',
            'created_by_platform_admin_id' => PlatformAdmin::factory(),
            'created_by_firm_user_id' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function permanent(): static
    {
        return $this->state(fn () => ['is_permanent' => true, 'retention_period_days' => null]);
    }

    public function forDocumentCategory(string $category, bool $allowsReplacement = false): static
    {
        return $this->state(fn () => [
            'record_type' => RetentionRecordType::DocumentCategory,
            'document_category' => $category,
            'allows_client_replacement' => $allowsReplacement,
        ]);
    }
}
