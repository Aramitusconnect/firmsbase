<?php

namespace Database\Factories;

use App\Enums\SignatureCertificateStatus;
use App\Models\DocumentHash;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatureCertificate>
 */
class SignatureCertificateFactory extends Factory
{
    protected $model = SignatureCertificate::class;

    public function definition(): array
    {
        return [
            'signature_request_id' => SignatureRequest::factory(),
            'firm_id' => fn (array $attributes) => SignatureRequest::query()->find($attributes['signature_request_id'])->firm_id,
            'status' => SignatureCertificateStatus::Generated->value,
            'certificate_data_json' => ['fixture' => true],
            'document_hash_id' => fn (array $attributes) => DocumentHash::factory()->create(['firm_id' => $attributes['firm_id']])->id,
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
