<?php

namespace Database\Factories;

use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SignatureRequest>
 */
class SignatureRequestFactory extends Factory
{
    protected $model = SignatureRequest::class;

    /**
     * signature_requests has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950035_prepare_row_level_security_
     * and_force_rls_on_signature_requests_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterExpenseFactory::create()'s docblock for the
     * full rationale, including why setDatabaseTenantContextForFirmId()
     * is used instead of setFirmContext()/runWithFirmContext() and why
     * the setting is deliberately left active rather than cleared. No
     * definition() root-cause rewrite is needed here (unlike the other
     * three factories in this batch) — the nested Document::factory()/
     * FirmUser::factory() calls below already self-handle their own
     * context via their own pre-existing context-hold create() overrides.
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

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => fn (array $attributes) => Document::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'status' => SignatureRequestStatus::Draft->value,
            'title' => $this->faker->sentence(4),
            'requested_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'document_id' => Document::factory()->create(['firm_id' => $firm->id])->id,
            'requested_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function attorneyReviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'attorney_reviewed_at' => now(),
            'attorney_reviewed_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'attorney_review_notes' => 'Reviewed for ESIGN/UETA suitability.',
        ]);
    }

    public function status(SignatureRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
