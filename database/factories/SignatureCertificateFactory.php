<?php

namespace Database\Factories;

use App\Enums\SignatureCertificateStatus;
use App\Models\DocumentHash;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SignatureCertificate>
 */
class SignatureCertificateFactory extends Factory
{
    protected $model = SignatureCertificate::class;

    /**
     * signature_certificates has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950038_prepare_row_level_security_
     * and_force_rls_on_signature_certificates_table.php), so every
     * INSERT (test or app) must run under the row's own
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
     * The signature_certificates row is always tied to the SAME firm as
     * its OWN parent signature_request. The nested DocumentHash::factory()
     * call self-handles its own context via DocumentHashFactory's own
     * pre-existing context-hold create() override.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * SignatureRequest::factory()->create() as a plain PHP statement at
     * the top of definition(), and DocumentHash::factory()->create(...)
     * directly as an array value (also eager, since it is invoked
     * immediately rather than deferred behind a closure/lazy Factory
     * value) — real, committed rows every single time, even when
     * forRequest() below immediately overrides ALL THREE of
     * signature_request_id/firm_id/document_hash_id with caller-supplied
     * values. Fixed by memoizing the request behind lazy closures, and
     * making document_hash_id itself a lazy closure keyed off the
     * already-resolved firm_id, so nothing is created unless it
     * survives, unoverridden, to the final row.
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
            'status' => SignatureCertificateStatus::Generated->value,
            'certificate_data_json' => ['fixture' => true],
            'document_hash_id' => fn (array $attributes) => DocumentHash::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
            'generated_at' => now(),
        ];
    }

    public function forRequest(SignatureRequest $request, DocumentHash $hash): static
    {
        return $this->state(fn () => [
            'signature_request_id' => $request->id,
            'firm_id' => $request->firm_id,
            'document_hash_id' => $hash->id,
        ]);
    }
}
