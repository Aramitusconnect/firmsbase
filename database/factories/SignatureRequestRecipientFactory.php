<?php

namespace Database\Factories;

use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SignatureRequestRecipient>
 */
class SignatureRequestRecipientFactory extends Factory
{
    protected $model = SignatureRequestRecipient::class;

    /**
     * signature_request_recipients has permanent FORCE ROW LEVEL
     * SECURITY (see database/migrations/2026_08_27_950036_prepare_row_
     * level_security_and_force_rls_on_signature_request_recipients_table.php),
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterExpenseFactory::create()'s
     * docblock for the full rationale, including why
     * setDatabaseTenantContextForFirmId() is used instead of
     * setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared.
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
     * The signature_request_recipients row is always tied to the SAME
     * firm as its OWN parent signature_request.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * SignatureRequest::factory()->create() as a plain PHP statement at
     * the top of definition() — a real, committed SignatureRequest
     * every single time, even when forRequest() below immediately
     * overrides both signature_request_id and firm_id with a
     * caller-supplied request. Fixed by memoizing the request behind
     * lazy closures so nothing is created unless it survives,
     * unoverridden, to the final row.
     */
    private ?SignatureRequest $lazyRequest = null;

    public function definition(): array
    {
        $this->lazyRequest = null;

        return [
            'signature_request_id' => function () {
                $this->lazyRequest ??= SignatureRequest::factory()->create();

                return $this->lazyRequest->id;
            },
            'firm_id' => function () {
                $this->lazyRequest ??= SignatureRequest::factory()->create();

                return $this->lazyRequest->firm_id;
            },
            'recipient_type' => SignatureRecipientType::External->value,
            'signer_name' => $this->faker->name(),
            'signer_email' => $this->faker->safeEmail(),
            'status' => SignatureRequestStatus::Draft->value,
        ];
    }

    public function forRequest(SignatureRequest $request): static
    {
        return $this->state(fn () => [
            'signature_request_id' => $request->id,
            'firm_id' => $request->firm_id,
        ]);
    }

    public function status(SignatureRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function consented(string $textVersion = 'consent-v1'): static
    {
        return $this->state(fn () => [
            'status' => SignatureRequestStatus::Consented->value,
            'text_version' => $textVersion,
            'consented_at' => now(),
        ]);
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'status' => SignatureRequestStatus::Signed->value,
            'signed_at' => now(),
        ]);
    }
}
