<?php

namespace Database\Factories;

use App\Enums\SignatureCertificateStatus;
use App\Models\DocumentHash;
use App\Models\Firm;
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
     * Audit fix (eager-factory-side-effects audit, second pass): the
     * previous fix here (see this file's git history) memoized the
     * request behind a private $lazyRequest property that BOTH
     * signature_request_id AND firm_id derived from — but firm_id was
     * one of the derived keys, not the source of truth. A caller
     * overriding ONLY firm_id (e.g.
     * SignatureCertificate::factory()->create(['firm_id' => $firmA->id]),
     * the exact pattern SignatureAndPdfTenantIsolationTest uses, never
     * routed through forRequest()) left the signature_request_id closure
     * completely unaware of the override: it still ran
     * SignatureRequest::factory()->create() with no override of its own,
     * eagerly creating a real, wasted, UNRELATED SignatureRequest (+ its
     * own nested Firm/Document/FirmUser) and left the row referencing
     * that wrong request instead of the caller's real firm — a leak AND
     * a firm_id/signature_request_id ownership mismatch. document_hash_id
     * already derived from $attributes['firm_id'] correctly (so it was
     * NOT part of this bug); signature_request_id now mirrors that same,
     * already-correct convention. Fixed by making firm_id Laravel's own
     * lazy factory-relationship form (the single source of truth,
     * resolved first) and deriving signature_request_id from the
     * already-resolved $attributes['firm_id'] the same way
     * document_hash_id already did.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'signature_request_id' => fn (array $attributes) => SignatureRequest::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
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
