<?php

namespace Database\Factories;

use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatureRequestRecipient>
 */
class SignatureRequestRecipientFactory extends Factory
{
    protected $model = SignatureRequestRecipient::class;

    public function definition(): array
    {
        return [
            'signature_request_id' => SignatureRequest::factory(),
            'firm_id' => fn (array $attributes) => SignatureRequest::query()->find($attributes['signature_request_id'])->firm_id,
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
