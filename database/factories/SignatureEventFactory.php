<?php

namespace Database\Factories;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Models\FirmUser;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatureEvent>
 */
class SignatureEventFactory extends Factory
{
    protected $model = SignatureEvent::class;

    public function definition(): array
    {
        return [
            'signature_request_id' => SignatureRequest::factory(),
            'firm_id' => fn (array $attributes) => SignatureRequest::query()->find($attributes['signature_request_id'])->firm_id,
            'event_type' => SignatureEventType::RequestCreated->value,
            'actor_type' => SignatureEventActorType::FirmUser->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'created_at' => now(),
        ];
    }

    public function forRequest(SignatureRequest $request): static
    {
        return $this->state(fn () => [
            'signature_request_id' => $request->id,
            'firm_id' => $request->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $request->firm_id])->id,
        ]);
    }

    public function eventType(SignatureEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type->value]);
    }
}
