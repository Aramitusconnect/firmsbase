<?php

namespace Database\Factories;

use App\Models\EmailMessage;
use App\Models\EmailMessageLink;
use App\Models\FirmUser;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessageLink>
 */
class EmailMessageLinkFactory extends Factory
{
    protected $model = EmailMessageLink::class;

    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'firm_id' => fn (array $attributes) => EmailMessage::query()->find($attributes['email_message_id'])->firm_id,
            'matter_id' => fn (array $attributes) => Matter::factory()->create([
                'firm_id' => $attributes['firm_id'],
            ])->id,
            'client_id' => null,
            'linked_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create([
                'firm_id' => $attributes['firm_id'],
            ])->id,
            'is_primary' => true,
        ];
    }

    /**
     * Overrides email_message_id/firm_id together with matter_id and
     * linked_by_firm_user_id, so all four are guaranteed to belong to
     * the same firm regardless of state-application order relative to
     * definition()'s own lazily-resolved closures.
     */
    public function forMessage(EmailMessage $message): static
    {
        return $this->state(fn () => [
            'email_message_id' => $message->id,
            'firm_id' => $message->firm_id,
            'matter_id' => Matter::factory()->create(['firm_id' => $message->firm_id])->id,
            'linked_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $message->firm_id])->id,
        ]);
    }
}
