<?php

namespace Database\Factories;

use App\Enums\EmailBodyStatus;
use App\Enums\EmailMessageDirection;
use App\Enums\EmailStorageMode;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'firm_id' => fn (array $attributes) => EmailAccount::query()->find($attributes['email_account_id'])->firm_id,
            'provider_thread_id' => 'thread-'.$this->faker->uuid(),
            'provider_message_id' => 'msg-'.$this->faker->uuid(),
            'direction' => EmailMessageDirection::Inbound->value,
            'from_address' => $this->faker->safeEmail(),
            'to_addresses' => [$this->faker->safeEmail()],
            'subject' => $this->faker->sentence(4),
            'sent_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(9),
            'storage_mode' => EmailStorageMode::MetadataOnly->value,
            'body_status' => EmailBodyStatus::NotStored->value,
            'encrypted_body_ciphertext' => null,
            'encryption_key_id' => null,
            'has_attachments' => false,
        ];
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn () => [
            'firm_id' => $account->firm_id,
            'email_account_id' => $account->id,
        ]);
    }
}
