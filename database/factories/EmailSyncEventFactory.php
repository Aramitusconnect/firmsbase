<?php

namespace Database\Factories;

use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\EmailAccount;
use App\Models\EmailSyncEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSyncEvent>
 */
class EmailSyncEventFactory extends Factory
{
    protected $model = EmailSyncEvent::class;

    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'firm_id' => fn (array $attributes) => EmailAccount::query()->find($attributes['email_account_id'])->firm_id,
            'event_type' => EmailSyncEventType::SyncRun->value,
            'outcome' => EmailSyncOutcome::Success->value,
            'resulting_cursor' => (string) $this->faker->numberBetween(1, 100),
            'detail' => null,
            'created_at' => now(),
        ];
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn () => [
            'email_account_id' => $account->id,
            'firm_id' => $account->firm_id,
        ]);
    }
}
